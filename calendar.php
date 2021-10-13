<?php

/**
 * Calendar plugin for Roundcube webmail
 *
 * @author Lazlo Westerhof <hello@lazlo.me>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2010, Lazlo Westerhof <hello@lazlo.me>
 * Copyright (C) 2014-2015, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

use Calendar\Controllers\EventController;
use Calendar\Controllers\MailController;
use Calendar\Controllers\ControllersManager;
use Calendar\calendar_ui;

class calendar extends rcube_plugin
{
    const FREEBUSY_UNKNOWN = 0;
    const FREEBUSY_FREE = 1;
    const FREEBUSY_BUSY = 2;
    const FREEBUSY_TENTATIVE = 3;
    const FREEBUSY_OOF = 4;

    const SESSION_KEY = 'calendar_temp';

    public $task = '?(?!logout).*';
    public $rc;
    public $lib;
    public $resources_dir;
    public $home;
    public $urlbase;
    public $timezone;
    public $timezone_offset;
    public $gmt_offset;
    public $ui;
    // private $ical;   __get()
    // private $itip;   __get()
    // private $driver; __get()
    // private EventController $eventController;
    // private MailController $mailController;
    private $_driver = null;
    private $_cals = null;
    private $_cal_driver_map = null;
    private ?ControllersManager $controllersManager = null;



    public $defaults = array(
        'calendar_default_view' => "agendaWeek",
        'calendar_timeslots'    => 2,
        'calendar_work_start'   => 6,
        'calendar_work_end'     => 18,
        'calendar_agenda_range' => 60,
        'calendar_agenda_sections' => 'smart',
        'calendar_event_coloring'  => 0,
        'calendar_time_indicator'  => true,
        'calendar_allow_invite_shared' => false,
        'calendar_itip_send_option'    => 3,
        'calendar_itip_after_action'   => 0,
    );




    /**
     * Plugin initialization.
     */
    function init()
    {
        $this->rc = rcube::get_instance();

        // Fix character encoding
        $this->rc->db->query("SET NAMES 'utf8mb4'"); // TODO: Compatibility with other DB drivers

        //register type of task
        $this->register_task('calendar');

        // load calendar configuration
        $this->load_config();

        //loads driver
        $this->load_driver();

        // ui and dependent plugins setup
        $this->setup();

        // register ControllerManger which is responsible for initializing needed Controller
        $this->controllersManager = new ControllersManager($this->rc, $this->driver, $this);

        //register used Controllers
        $this->controllersManager->register('MailController');
        $this->controllersManager->register('EventController');

        //register custom actions
        $this->register_actions();

        //add hooks to predefined functions
        $this->add_hooks();

        if ($this->rc->task == 'calendar') {
            $this->rc->output->set_env('refresh_interval', $this->rc->config->get('calendar_sync_period', 0));
        }
    }

    /**
     * Setup basic plugin environment and UI
     */
    protected function setup()
    {
        $this->require_plugin('libcalendaring');
        $this->require_plugin('libkolab');

        $this->lib             = libcalendaring::get_instance();
        $this->timezone        = $this->lib->timezone;
        $this->gmt_offset      = $this->lib->gmt_offset;
        $this->dst_active      = $this->lib->dst_active;
        $this->timezone_offset = $this->gmt_offset / 3600 - $this->dst_active;

        // load localizations
        $this->add_texts('localization/', $this->rc->task == 'calendar' && (!$this->rc->action || $this->rc->action == 'print'));

        $this->ui = new calendar_ui($this);
    }

    private function register_actions()
    {
        $this->register_action('index', array($this, 'calendar_view'));
        $this->register_action('calendar', array($this, 'calendar_action'));

        $this->register_action('upload', array($this, 'attachment_upload'));
        $this->register_action('get-attachment', array($this, 'attachment_get'));
        $this->register_action('freebusy-status', array($this, 'freebusy_status'));
        $this->register_action('freebusy-times', array($this, 'freebusy_times'));
        $this->register_action('print', array($this, 'print_view'));

        $this->register_action('resources-list', array($this, 'resources_list'));
        $this->register_action('resources-owner', array($this, 'resources_owner'));
        $this->register_action('resources-calendar', array($this, 'resources_calendar'));
        $this->register_action('resources-autocomplete', array($this, 'resources_autocomplete'));
    }

    private function add_hooks()
    {
        // hooks for calendar
        $this->add_hook('refresh', array($this, 'refresh'));


        // hooks for calendar settings
        $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
        $this->add_hook('preferences_list', array($this, 'preferences_list'));
        $this->add_hook('preferences_save', array($this, 'preferences_save'));

        // optional hooks for setting birthday date of contacts in calendar
        if ($this->rc->config->get('calendar_contact_birthdays')) {
            $this->add_hook('contact_update', array($this, 'contact_update'));
            $this->add_hook('contact_create', array($this, 'contact_update'));
        }

        // add hooks to display alarms
        $this->add_hook('pending_alarms', array($this, 'pending_alarms'));
        $this->add_hook('dismiss_alarms', array($this, 'dismiss_alarms'));

        // catch iTIP confirmation requests that don're require a valid session
        if ($this->rc->action == 'attend' && !empty($_REQUEST['_t'])) {
            $this->add_hook('startup', array($this, 'itip_attend_response'));
        } else if ($this->rc->action == 'feed' && !empty($_REQUEST['_cal'])) {
            $this->add_hook('startup', array($this, 'ical_feed_export'));
        } else if ($this->rc->task != 'login') {
            // default startup routine
            $this->add_hook('startup', array($this, 'startup'));
        }

        $this->add_hook('user_delete', array($this, 'user_delete'));
    }

    /**
     * Startup hook
     */
    public function startup($args)
    {

        // load Calendar user interface
        if (!$this->rc->output->ajax_call && (!$this->rc->output->env['framed'] || $args['action'] == 'preview')) {
            $this->ui->init();

            // settings are required in (almost) every GUI step
            if ($args['action'] != 'attend')
                $this->rc->output->set_env('calendar_settings', $this->load_settings());
        }

        if ($args['task'] == 'calendar' && $args['action'] != 'save-pref') {
            // remove undo information...
            if ($undo = $_SESSION['calendar_event_undo']) {
                // ...after timeout
                $undo_time = $this->rc->config->get('undo_timeout', 0);
                if ($undo['ts'] < time() - $undo_time) {
                    $this->rc->session->remove('calendar_event_undo');
                    // @TODO: do EXPUNGE on kolab objects?
                }
            }
        } else if ($args['task'] == 'mail') {


            // add 'Create event' item to message menu
            if ($this->api->output->type == 'html' && $_GET['_rel'] != 'event') {
                $this->api->add_content(
                    html::tag(
                        'li',
                        array('role' => 'menuitem'),
                        $this->api->output->button(array(
                            'command'  => 'calendar-create-from-mail',
                            'label'    => 'calendar.createfrommail',
                            'type'     => 'link',
                            'classact' => 'icon calendarlink active',
                            'class'    => 'icon calendarlink disabled',
                            'innerclass' => 'icon calendar',
                        ))
                    ),
                    'messagemenu'
                );

                $this->api->output->add_label('calendar.createfrommail');
            }
        }
        $this->controllersManager->match($args['task']);
    }

    /**
     * Helper method to load the backend driver according to local config
     * 
     */
    public function load_driver()
    {
        $driver_name = $this->rc->config->get('calendar_driver');
        $driver_class_name = $driver_name . "_driver";

        //abstract class which driver have to implement
        require_once($this->home . '/drivers/calendar_driver.php');
        require_once($this->home . '/drivers/' . $driver_name . '/' . $driver_class_name . '.php');

        $driver = new $driver_class_name($this);

        if ($driver->undelete)
            $driver->undelete = $this->rc->config->get('undo_timeout', 0) > 0;

        $this->_driver = $driver;

        return $driver;
    }


    /**
     * Helper function to build calendar to driver map and calendar array.
     * @return array List of calendar properties.
     */
    public function get_calendars()
    {
        if ($this->_cals == null || $this->_cal_driver_map == null) {
            $this->_cals = array();
            $this->_cal_driver_map = array();

            foreach ((array)$this->driver->list_calendars() as $id => $prop) {
                $prop["driver"] = get_class($this->driver);
                $this->_cals[$id] = $prop;
                $this->_cal_driver_map[$id] = $this->driver;
            }
        }

        if ($this->driver->undelete)
            $this->driver->undelete = $this->rc->config->get('undo_timeout', 0) > 0;
    }

    /**
     * Load iTIP functions
     */
    private function load_itip()
    {
        if (!$this->itip) {
            require_once($this->home . '/lib/calendar_itip.php');
            $this->itip = new calendar_itip($this);

            if ($this->rc->config->get('kolab_invitation_calendars'))
                $this->itip->set_rsvp_actions(array('accepted', 'tentative', 'declined', 'delegated', 'needs-action'));
        }

        return $this->itip;
    }

    /**
     * Load iCalendar functions
     */
    public function get_ical()
    {
        if (!$this->ical) {
            $this->ical = libcalendaring::get_ical();
        }

        return $this->ical;
    }

    /**
     * Get properties of the calendar this user has specified as default
     */
    public function get_default_calendar($sensitivity = null, $calendars = null)
    {
        $default_id = $this->rc->config->get('calendar_default_calendar');
        $calendar   = $calendars[$default_id] ?: null;

        if (!$calendar || $sensitivity) {
            foreach ($calendars as $cal) {
                if ($sensitivity && $cal['subtype'] == $sensitivity) {
                    $calendar = $cal;
                    break;
                }
                if ($cal['default'] && $cal['editable']) {
                    $calendar = $cal;
                }
                if ($cal['editable']) {
                    $first = $cal;
                }
            }
        }

        return $calendar ?: $first;
    }

    /**
     * Render the main calendar view from skin template
     */
    function calendar_view()
    {
        $this->rc->output->set_pagetitle($this->gettext('calendar'));

        // Add JS files to the page header
        $this->ui->addJS();

        $this->ui->init_templates();
        $this->rc->output->add_label('lowest', 'low', 'normal', 'high', 'highest', 'delete', 'cancel', 'uploading', 'noemailwarning', 'close');

        // initialize attendees autocompletion
        $this->rc->autocomplete_init();

        $this->rc->output->set_env('timezone', $this->timezone->getName());
        $this->rc->output->set_env('calendar_driver', $this->rc->config->get('calendar_driver'), false);
        $this->rc->output->set_env('calendar_resources', (bool)$this->rc->config->get('calendar_resources_driver'));
        $this->rc->output->set_env('identities-selector', $this->ui->identity_select(array(
            'id'         => 'edit-identities-list',
            'aria-label' => $this->gettext('roleorganizer'),
            'class'      => 'form-control custom-select',
        )));

        $view = rcube_utils::get_input_value('view', rcube_utils::INPUT_GPC);
        if (in_array($view, array('agendaWeek', 'agendaDay', 'month', 'list')))
            $this->rc->output->set_env('view', $view);

        if ($date = rcube_utils::get_input_value('date', rcube_utils::INPUT_GPC))
            $this->rc->output->set_env('date', $date);

        if ($msgref = rcube_utils::get_input_value('itip', rcube_utils::INPUT_GPC))
            $this->rc->output->set_env('itip_events', $this->itip_events($msgref));

        $this->rc->output->send("calendar.calendar");
    }

    /**
     * Handler for preferences_sections_list hook.
     * Adds Calendar settings sections into preferences sections list.
     *
     * @param array Original parameters
     * @return array Modified parameters
     */
    function preferences_sections_list($p)
    {
        $p['list']['calendar'] = array(
            'id' => 'calendar', 'section' => $this->gettext('calendar'),
        );

        return $p;
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into Calendar settings sections in Preferences.
     *
     * @param array Original parameters
     * @return array Modified parameters
     */
    function preferences_list($p)
    {
        if ($p['section'] != 'calendar') {
            return $p;
        }

        $no_override = array_flip((array)$this->rc->config->get('dont_override'));

        $p['blocks']['view']['name'] = $this->gettext('mainoptions');

        if (!isset($no_override['calendar_default_view'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_default_view';
            $view = $this->rc->config->get('calendar_default_view', $this->defaults['calendar_default_view']);
            $select = new html_select(array('name' => '_default_view', 'id' => $field_id));
            $select->add($this->gettext('day'), "agendaDay");
            $select->add($this->gettext('week'), "agendaWeek");
            $select->add($this->gettext('month'), "month");
            $select->add($this->gettext('agenda'), "list");
            $p['blocks']['view']['options']['default_view'] = array(
                'title' => html::label($field_id, rcube::Q($this->gettext('default_view'))),
                'content' => $select->show($view == 'table' ? 'list' : $view),
            );
        }

        if (!isset($no_override['calendar_timeslots'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_timeslot';
            $choices = array('1', '2', '3', '4', '6');
            $select = new html_select(array('name' => '_timeslots', 'id' => $field_id));
            $select->add($choices);
            $p['blocks']['view']['options']['timeslots'] = array(
                'title' => html::label($field_id, rcube::Q($this->gettext('timeslots'))),
                'content' => $select->show(strval($this->rc->config->get('calendar_timeslots', $this->defaults['calendar_timeslots']))),
            );
        }

        if (!isset($no_override['calendar_first_day'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_firstday';
            $select = new html_select(array('name' => '_first_day', 'id' => $field_id));
            $select->add($this->gettext('sunday'), '0');
            $select->add($this->gettext('monday'), '1');
            $select->add($this->gettext('tuesday'), '2');
            $select->add($this->gettext('wednesday'), '3');
            $select->add($this->gettext('thursday'), '4');
            $select->add($this->gettext('friday'), '5');
            $select->add($this->gettext('saturday'), '6');
            $p['blocks']['view']['options']['first_day'] = array(
                'title' => html::label($field_id, rcube::Q($this->gettext('first_day'))),
                'content' => $select->show(strval($this->rc->config->get('calendar_first_day', $this->defaults['calendar_first_day']))),
            );
        }

        if (!isset($no_override['calendar_first_hour'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $time_format = $this->rc->config->get('time_format', libcalendaring::to_php_date_format($this->rc->config->get('calendar_time_format', $this->defaults['calendar_time_format'])));
            $select_hours = new html_select();
            for ($h = 0; $h < 24; $h++)
                $select_hours->add(date($time_format, mktime($h, 0, 0)), $h);

            $field_id = 'rcmfd_firsthour';
            $p['blocks']['view']['options']['first_hour'] = array(
                'title' => html::label($field_id, rcube::Q($this->gettext('first_hour'))),
                'content' => $select_hours->show($this->rc->config->get('calendar_first_hour', $this->defaults['calendar_first_hour']), array('name' => '_first_hour', 'id' => $field_id)),
            );
        }

        if (!isset($no_override['calendar_work_start'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id   = 'rcmfd_workstart';
            $work_start = $this->rc->config->get('calendar_work_start', $this->defaults['calendar_work_start']);
            $work_end   = $this->rc->config->get('calendar_work_end', $this->defaults['calendar_work_end']);
            $p['blocks']['view']['options']['workinghours'] = array(
                'title'   => html::label($field_id, rcube::Q($this->gettext('workinghours'))),
                'content' => html::div(
                    'input-group',
                    $select_hours->show($work_start, array('name' => '_work_start', 'id' => $field_id))
                        . html::span('input-group-append input-group-prepend', html::span('input-group-text', ' &mdash; '))
                        . $select_hours->show($work_end, array('name' => '_work_end', 'id' => $field_id))
                )
            );
        }

        if (!isset($no_override['calendar_event_coloring'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_coloring';
            $select_colors = new html_select(array('name' => '_event_coloring', 'id' => $field_id));
            $select_colors->add($this->gettext('coloringmode0'), 0);
            $select_colors->add($this->gettext('coloringmode1'), 1);
            $select_colors->add($this->gettext('coloringmode2'), 2);
            $select_colors->add($this->gettext('coloringmode3'), 3);

            $p['blocks']['view']['options']['eventcolors'] = array(
                'title'   => html::label($field_id, rcube::Q($this->gettext('eventcoloring'))),
                'content' => $select_colors->show($this->rc->config->get('calendar_event_coloring', $this->defaults['calendar_event_coloring'])),
            );
        }

        if (!isset($no_override['calendar_default_alarm_type']) || !isset($no_override['calendar_default_alarm_offset'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_alarm';
            $select_type = new html_select(array('name' => '_alarm_type', 'id' => $field_id));
            $select_type->add($this->gettext('none'), '');
            $types = array();
            foreach ($this->driver->alarm_types as $type) {
                $types[$type] = $type;
            }
            foreach ($types as $type) {
                $select_type->add($this->gettext(strtolower("alarm{$type}option"), 'libcalendaring'), $type);
            }
            $p['blocks']['view']['options']['alarmtype'] = array(
                'title' => html::label($field_id, rcube::Q($this->gettext('defaultalarmtype'))),
                'content' => $select_type->show($this->rc->config->get('calendar_default_alarm_type', '')),
            );
        }

        if (!isset($no_override['calendar_default_alarm_offset'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_alarm';
            $input_value = new html_inputfield(array('name' => '_alarm_value', 'id' => $field_id . 'value', 'size' => 3));
            $select_offset = new html_select(array('name' => '_alarm_offset', 'id' => $field_id . 'offset'));
            foreach (array('-M', '-H', '-D', '+M', '+H', '+D') as $trigger)
                $select_offset->add($this->rc->gettext('trigger' . $trigger, 'libcalendaring'), $trigger);

            $preset = libcalendaring::parse_alarm_value($this->rc->config->get('calendar_default_alarm_offset', '-15M'));
            $p['blocks']['view']['options']['alarmoffset'] = array(
                'title' => html::label($field_id . 'value', rcube::Q($this->gettext('defaultalarmoffset'))),
                'content' => $input_value->show($preset[0]) . ' ' . $select_offset->show($preset[1]),
            );
        }

        if (!isset($no_override['calendar_default_calendar'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }
            // default calendar selection
            $field_id = 'rcmfd_default_calendar';
            $select_cal = new html_select(array('name' => '_default_calendar', 'id' => $field_id, 'is_escaped' => true));
            foreach ((array)$this->driver->list_calendars() as $id => $prop) {
                $select_cal->add($prop['name'], strval($id));
                if ($prop['default'])
                    $default_calendar = $id;
            }
            $p['blocks']['view']['options']['defaultcalendar'] = array(
                'title' => html::label($field_id . 'value', rcube::Q($this->gettext('defaultcalendar'))),
                'content' => $select_cal->show($this->rc->config->get('calendar_default_calendar', $default_calendar)),
            );

            $field_id = 'sync_period';
            $input_value = new html_inputfield(array('name' => '_sync_period', 'id' => $field_id . 'value', 'size' => 4));
            $p['blocks']['view']['options']['syncperiod'] = array(
                'title' => html::label($field_id, rcube::Q($this->gettext('syncperiod'))),
                'content' => $input_value->show($this->rc->config->get('calendar_sync_period', $sync_period)),
            );
        }

        $p['blocks']['view']['options']['defaultcalendar'] = array(
            'title' => html::label($field_id, rcube::Q($this->gettext('defaultcalendar'))),
            'content' => $select_cal->show($this->rc->config->get('calendar_default_calendar', $default_calendar)),
        );

        $field_id = 'sync_period';
        $input_value = new html_inputfield(array('name' => '_sync_period', 'id' => $field_id . 'value', 'size' => 4));
        $p['blocks']['view']['options']['syncperiod'] = array(
            'title' => html::label($field_id, rcube::Q($this->gettext('syncperiod'))),
            'content' => $input_value->show($this->rc->config->get('calendar_sync_period', $sync_period)),
        );

        if (!isset($no_override['calendar_show_weekno'])) {
            if (!$p['current']) {
                $p['blocks']['view']['content'] = true;
                return $p;
            }

            $field_id   = 'rcmfd_show_weekno';
            $select = new html_select(array('name' => '_show_weekno', 'id' => $field_id));
            $select->add($this->gettext('weeknonone'), -1);
            $select->add($this->gettext('weeknodatepicker'), 0);
            $select->add($this->gettext('weeknoall'), 1);

            $p['blocks']['view']['options']['show_weekno'] = array(
                'title' => html::label($field_id, rcube::Q($this->gettext('showweekno'))),
                'content' => $select->show(intval($this->rc->config->get('calendar_show_weekno'))),
            );
        }

        $p['blocks']['itip']['name'] = $this->gettext('itipoptions');

        // Invitations handling
        if (!isset($no_override['calendar_itip_after_action'])) {
            if (!$p['current']) {
                $p['blocks']['itip']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_after_action';
            $select   = new html_select(array(
                'name' => '_after_action', 'id' => $field_id,
                'onchange' => "\$('#{$field_id}_select')[this.value == 4 ? 'show' : 'hide']()"
            ));

            $select->add($this->gettext('afternothing'), '');
            $select->add($this->gettext('aftertrash'), 1);
            $select->add($this->gettext('afterdelete'), 2);
            $select->add($this->gettext('afterflagdeleted'), 3);
            $select->add($this->gettext('aftermoveto'), 4);

            $val = $this->rc->config->get('calendar_itip_after_action', $this->defaults['calendar_itip_after_action']);
            if ($val !== null && $val !== '' && !is_int($val)) {
                $folder = $val;
                $val    = 4;
            }

            $folders = $this->rc->folder_selector(array(
                'id'            => $field_id . '_select',
                'name'          => '_after_action_folder',
                'maxlength'     => 30,
                'folder_filter' => 'mail',
                'folder_rights' => 'w',
                'style'         => $val !== 4 ? 'display:none' : '',
            ));

            $p['blocks']['itip']['options']['after_action'] = array(
                'title'   => html::label($field_id, rcube::Q($this->gettext('afteraction'))),
                'content' => html::div('input-group input-group-combo', $select->show($val) . $folders->show($folder)),
            );
        }

        // category definitions
        if (!$this->driver->nocategories && !isset($no_override['calendar_categories'])) {
            $p['blocks']['categories']['name'] = $this->gettext('categories');

            if (!$p['current']) {
                $p['blocks']['categories']['content'] = true;
                return $p;
            }

            $categories = (array) $this->driver->list_categories();
            $categories_list = '';
            foreach ($categories as $name => $color) {
                $key = md5($name);
                $field_class = 'rcmfd_category_' . str_replace(' ', '_', $name);
                $category_remove = html::span('input-group-append', html::a(array(
                    'class'   => 'button icon delete input-group-text',
                    'onclick' => '$(this).parent().parent().remove()',
                    'title'   => $this->gettext('remove_category'),
                    'href'    => '#rcmfd_new_category',
                ), html::span('inner', $this->gettext('delete'))));
                $category_name  = new html_inputfield(array('name' => "_categories[$key]", 'class' => $field_class, 'size' => 30, 'disabled' => $this->driver->categoriesimmutable));
                $category_color = new html_inputfield(array('name' => "_colors[$key]", 'class' => "$field_class colors", 'size' => 6));
                $hidden = $this->driver->categoriesimmutable ? html::tag('input', array('type' => 'hidden', 'name' => "_categories[$key]", 'value' => $name)) : '';
                $categories_list .= $hidden . html::div('input-group', $category_name->show($name) . $category_color->show($color) . $category_remove);
            }

            $p['blocks']['categories']['options']['category_' . $name] = array(
                'content' => html::div(array('id' => 'calendarcategories'), $categories_list),
            );

            $field_id = 'rcmfd_new_category';
            $new_category = new html_inputfield(array('name' => '_new_category', 'id' => $field_id, 'size' => 30));
            $add_category = html::span('input-group-append', html::a(array(
                'type'    => 'button',
                'class'   => 'button create input-group-text',
                'title'   => $this->gettext('add_category'),
                'onclick' => 'rcube_calendar_add_category()',
                'href'    => '#rcmfd_new_category',
            ), html::span('inner', $this->gettext('add_category'))));
            $p['blocks']['categories']['options']['categories'] = array(
                'content' => html::div('input-group', $new_category->show('') . $add_category),
            );

            $this->rc->output->add_label('delete', 'calendar.remove_category');
            $this->rc->output->add_script('function rcube_calendar_add_category() {
          var name = $("#rcmfd_new_category").val();
          if (name.length) {
            var button_label = rcmail.gettext("calendar.remove_category");
            var input = $("<input>").attr({type: "text", name: "_categories[]", size: 30, "class": "form-control"}).val(name);
            var color = $("<input>").attr({type: "text", name: "_colors[]", size: 6, "class": "colors form-control"}).val("000000");
            var button = $("<a>").attr({"class": "button icon delete input-group-text", title: button_label, href: "#rcmfd_new_category"})
              .click(function() { $(this).parent().parent().remove(); })
              .append($("<span>").addClass("inner").text(rcmail.gettext("delete")));

            $("<div>").addClass("input-group").append(input).append(color).append($("<span class=\'input-group-append\'>").append(button))
              .appendTo("#calendarcategories");
            color.minicolors(rcmail.env.minicolors_config || {});
            $("#rcmfd_new_category").val("");
          }
        }', 'foot');

            $this->rc->output->add_script('$("#rcmfd_new_category").keypress(function(event) {
          if (event.which == 13) {
            rcube_calendar_add_category();
            event.preventDefault();
          }
        });
        ', 'docready');

            // load miniColors js/css files
            jqueryui::miniColors();
        }


        // virtual birthdays calendar TODO
        if (!isset($no_override['calendar_contact_birthdays'])) {
            $p['blocks']['birthdays']['name'] = $this->gettext('birthdayscalendar');

            if (!$p['current']) {
                $p['blocks']['birthdays']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_contact_birthdays';
            $input    = new html_checkbox(array('name' => '_contact_birthdays', 'id' => $field_id, 'value' => 1, 'onclick' => '$(".calendar_birthday_props").prop("disabled",!this.checked)'));

            $p['blocks']['birthdays']['options']['contact_birthdays'] = array(
                'title'   => html::label($field_id, $this->gettext('displaybirthdayscalendar')),
                'content' => $input->show($this->rc->config->get('calendar_contact_birthdays') ? 1 : 0),
            );

            $input_attrib = array(
                'class' => 'calendar_birthday_props',
                'disabled' => !$this->rc->config->get('calendar_contact_birthdays'),
            );

            $sources = array();
            $checkbox = new html_checkbox(array('name' => '_birthday_adressbooks[]') + $input_attrib);
            foreach ($this->rc->get_address_sources(false, true) as $source) {
                $active = in_array($source['id'], (array)$this->rc->config->get('calendar_birthday_adressbooks', array())) ? $source['id'] : '';
                $sources[] = html::tag('li', null, html::label(null, $checkbox->show($active, array('value' => $source['id'])) . rcube::Q($source['realname'] ?: $source['name'])));
            }

            $p['blocks']['birthdays']['options']['birthday_adressbooks'] = array(
                'title'   => rcube::Q($this->gettext('birthdayscalendarsources')),
                'content' => html::tag('ul', 'proplist', implode("\r\n", $sources)),
            );

            $field_id = 'rcmfd_birthdays_alarm';
            $select_type = new html_select(array('name' => '_birthdays_alarm_type', 'id' => $field_id) + $input_attrib);
            $select_type->add($this->gettext('none'), '');
            foreach ($this->driver->alarm_types as $type) {
                $select_type->add($this->rc->gettext(strtolower("alarm{$type}option"), 'libcalendaring'), $type);
            }

            $input_value = new html_inputfield(array('name' => '_birthdays_alarm_value', 'id' => $field_id . 'value', 'size' => 3) + $input_attrib);
            $select_offset = new html_select(array('name' => '_birthdays_alarm_offset', 'id' => $field_id . 'offset') + $input_attrib);
            foreach (array('-M', '-H', '-D') as $trigger)
                $select_offset->add($this->rc->gettext('trigger' . $trigger, 'libcalendaring'), $trigger);

            $preset      = libcalendaring::parse_alarm_value($this->rc->config->get('calendar_birthdays_alarm_offset', '-1D'));
            $preset_type = $this->rc->config->get('calendar_birthdays_alarm_type', '');

            $p['blocks']['birthdays']['options']['birthdays_alarmoffset'] = array(
                'title'   => html::label($field_id, rcube::Q($this->gettext('showalarms'))),
                'content' => html::div('input-group', $select_type->show($preset_type) . $input_value->show($preset[0]) . ' ' . $select_offset->show($preset[1])),
            );
        }

        return $p;
    }

    /**
     * Handler for preferences_save hook.
     * Executed on Calendar settings form submit.
     *
     * @param array Original parameters
     * @return array Modified parameters
     */
    function preferences_save($p)
    {
        if ($p['section'] == 'calendar') {

            // compose default alarm preset value
            $alarm_offset  = rcube_utils::get_input_value('_alarm_offset', rcube_utils::INPUT_POST);
            $alarm_value   = rcube_utils::get_input_value('_alarm_value', rcube_utils::INPUT_POST);
            $default_alarm = $alarm_offset[0] . intval($alarm_value) . $alarm_offset[1];

            $birthdays_alarm_offset = rcube_utils::get_input_value('_birthdays_alarm_offset', rcube_utils::INPUT_POST);
            $birthdays_alarm_value  = rcube_utils::get_input_value('_birthdays_alarm_value', rcube_utils::INPUT_POST);
            $birthdays_alarm_value  = $birthdays_alarm_offset[0] . intval($birthdays_alarm_value) . $birthdays_alarm_offset[1];

            $p['prefs'] = array(
                'calendar_default_view'       => rcube_utils::get_input_value('_default_view', rcube_utils::INPUT_POST),
                'calendar_timeslots'          => intval(rcube_utils::get_input_value('_timeslots', rcube_utils::INPUT_POST)),
                'calendar_first_day'          => intval(rcube_utils::get_input_value('_first_day', rcube_utils::INPUT_POST)),
                'calendar_first_hour'         => intval(rcube_utils::get_input_value('_first_hour', rcube_utils::INPUT_POST)),
                'calendar_work_start'         => intval(rcube_utils::get_input_value('_work_start', rcube_utils::INPUT_POST)),
                'calendar_work_end'           => intval(rcube_utils::get_input_value('_work_end', rcube_utils::INPUT_POST)),
                'calendar_sync_period'           => intval(rcube_utils::get_input_value('_sync_period', rcube_utils::INPUT_POST)),
                'calendar_show_weekno'        => intval(rcube_utils::get_input_value('_show_weekno', rcube_utils::INPUT_POST)),
                'calendar_event_coloring'         => intval(rcube_utils::get_input_value('_event_coloring', rcube_utils::INPUT_POST)),
                'calendar_default_alarm_type'     => rcube_utils::get_input_value('_alarm_type', rcube_utils::INPUT_POST),
                'calendar_default_alarm_offset'   => $default_alarm,
                'calendar_default_calendar'       => rcube_utils::get_input_value('_default_calendar', rcube_utils::INPUT_POST),
                'calendar_date_format'         => null,  // clear previously saved values
                'calendar_time_format'         => null,
                'calendar_contact_birthdays'      => rcube_utils::get_input_value('_contact_birthdays', rcube_utils::INPUT_POST) ? true : false,
                'calendar_birthday_adressbooks'   => (array) rcube_utils::get_input_value('_birthday_adressbooks', rcube_utils::INPUT_POST),
                'calendar_birthdays_alarm_type'   => rcube_utils::get_input_value('_birthdays_alarm_type', rcube_utils::INPUT_POST),
                'calendar_birthdays_alarm_offset' => $birthdays_alarm_value ?: null,
                'calendar_itip_after_action'      => intval(rcube_utils::get_input_value('_after_action', rcube_utils::INPUT_POST)),
            );

            if ($p['prefs']['calendar_itip_after_action'] == 4) {
                $p['prefs']['calendar_itip_after_action'] = rcube_utils::get_input_value('_after_action_folder', rcube_utils::INPUT_POST, true);
            }

            // categories

            if (!$this->driver->nocategories) {
                $old_categories = $new_categories = array();
                foreach ($this->driver->list_categories() as $name => $color) {
                    $old_categories[md5($name)] = $name;
                }

                $categories = (array) rcube_utils::get_input_value('_categories', rcube_utils::INPUT_POST);
                $colors     = (array) rcube_utils::get_input_value('_colors', rcube_utils::INPUT_POST);

                foreach ($categories as $key => $name) {
                    if (!isset($colors[$key])) {
                        continue;
                    }

                    $color = preg_replace('/^#/', '', strval($colors[$key]));

                    // rename categories in existing events -> driver's job
                    if ($oldname = $old_categories[$key]) {
                        $this->driver->replace_category($oldname, $name, $color);
                        unset($old_categories[$key]);
                    } else
                        $this->driver->add_category($name, $color);

                    $new_categories[$name] = $color;
                }

                // these old categories have been removed, alter events accordingly -> driver's job
                foreach ((array)$old_categories[$key] as $key => $name) {
                    $this->driver->remove_category($name);
                }

                $p['prefs']['calendar_categories'] = $new_categories;
            }
        }

        return $p;
    }

    /**
     * Dispatcher for calendar actions initiated by the client
     */
    function calendar_action()
    {
        $action = rcube_utils::get_input_value('action', rcube_utils::INPUT_GPC);
        $cal    = rcube_utils::get_input_value('c', rcube_utils::INPUT_GPC);
        $success = $reload = false;

        if (isset($cal['showalarms']))
            $cal['showalarms'] = intval($cal['showalarms']);

        switch ($action) {
            case "form-new":
            case "form-edit":
                echo $this->ui->calendar_editform($action, $cal);
                exit;
            case "new":
                $success = $this->driver->create_calendar($cal);
                $reload = true;
                break;
            case "edit":
                $success = $this->driver->edit_calendar($cal);
                $reload = true;
                break;
            case "delete":
                if ($success = $this->driver->delete_calendar($cal))
                    $this->rc->output->command('plugin.destroy_source', array('id' => $cal['id']));
                break;
            case "subscribe":
                if (!$this->driver->subscribe_calendar($cal))
                    $this->rc->output->show_message($this->gettext('errorsaving'), 'error');
                return;
            case "search":
                $results    = array();
                $color_mode = $this->rc->config->get('calendar_event_coloring', $this->defaults['calendar_event_coloring']);
                $query      = rcube_utils::get_input_value('q', rcube_utils::INPUT_GPC);
                $source     = rcube_utils::get_input_value('source', rcube_utils::INPUT_GPC);

                $search_more_results = false;
                foreach ((array)$this->driver->search_calendars($query, $source) as $id => $prop) {
                    $editname = $prop['editname'];
                    unset($prop['editname']);  // force full name to be displayed
                    $prop['active'] = false;

                    // let the UI generate HTML and CSS representation for this calendar
                    $html = $this->ui->calendar_list_item($id, $prop, $jsenv);
                    $cal = $jsenv[$id];
                    $cal['editname'] = $editname;
                    $cal['html'] = $html;
                    if (!empty($prop['color']))
                        $cal['css'] = $this->ui->calendar_css_classes($id, $prop, $color_mode);

                    $results[] = $cal;
                }

                $search_more_results |= $this->driver->search_more_results;

                // report more results available
                if ($search_more_results)
                    $this->rc->output->show_message('autocompletemore', 'info');

                $this->rc->output->command('multi_thread_http_response', $results, rcube_utils::get_input_value('_reqid', rcube_utils::INPUT_GPC));
                return;
        }

        if ($success)
            $this->rc->output->show_message('successfullysaved', 'confirmation');
        else {
            $error_msg = $this->gettext('errorsaving') . ($this->driver && $this->driver->last_error ? ': ' . $this->driver->last_error : '');
            $this->rc->output->show_message($error_msg, 'error');
        }

        $this->rc->output->command('plugin.unlock_saving');

        if ($success && $reload)
            $this->rc->output->command('plugin.reload_view');
    }

    /**
     * Load event data from an iTip message attachment
     */
    public function itip_events($msgref)
    {
        $path = explode('/', $msgref);
        $msg  = array_pop($path);
        $mbox = join('/', $path);
        list($uid, $mime_id) = explode('#', $msg);
        $events = array();

        if ($event = $this->lib->mail_get_itip_object($mbox, $uid, $mime_id, 'event')) {
            $partstat = 'NEEDS-ACTION';

            $event['id']        = $event['uid'];
            $event['temporary'] = true;
            $event['readonly']  = true;
            $event['calendar']  = '--invitation--itip';
            $event['className'] = 'fc-invitation-' . strtolower($partstat);
            $event['_mbox']     = $mbox;
            $event['_uid']      = $uid;
            $event['_part']     = $mime_id;

            $events[] = $this->eventController->serialize_event_for_ui($event, true);

            // add recurring instances
            if (!empty($event['recurrence'])) {
                // Some installations can't handle all occurrences (aborting the request w/o an error in log)
                $end = clone $event['start'];
                $end->add(new DateInterval($event['recurrence']['FREQ'] == 'DAILY' ? 'P1Y' : 'P10Y'));

                foreach ($this->driver->get_recurring_events($event, $event['start'], $end) as $recurring) {
                    $recurring['temporary'] = true;
                    $recurring['readonly']  = true;
                    $recurring['calendar']  = '--invitation--itip';
                    $events[] = $this->eventController->serialize_event_for_ui($recurring, true);
                }
            }
        }

        return $events;
    }

    /**
     * Handler for keep-alive requests
     * This will check for updated data in active calendars and sync them to the client
     */
    public function refresh($attr)
    {
        // refresh the entire calendar every 10th time to also sync deleted events
        if (rand(0, 10) == 10) {
            $this->rc->output->command('plugin.refresh_calendar', array('refetch' => true));
            return;
        }

        $counts = array();


        foreach ($this->driver->list_calendars(calendar_driver::FILTER_ACTIVE) as $cal) {
            $events = $this->driver->load_events(
                rcube_utils::get_input_value('start', rcube_utils::INPUT_GPC),
                rcube_utils::get_input_value('end', rcube_utils::INPUT_GPC),
                rcube_utils::get_input_value('q', rcube_utils::INPUT_GPC),
                $cal['id'],
                1,
                $attr['last']
            );

            foreach ($events as $event) {
                $this->rc->output->command(
                    'plugin.refresh_calendar',
                    array('source' => $cal['id'], 'update' => $this->eventController->serialize_event_for_ui($event))
                );
            }

            // refresh count for this calendar
            if ($cal['counts']) {
                $today = new DateTime('today 00:00:00', $this->timezone);
                $counts += $this->driver->count_events($cal['id'], $today->format('U'));
            }
        }


        if (!empty($counts)) {
            $this->rc->output->command('plugin.update_counts', array('counts' => $counts));
        }
    }

    /**
     * Handler for pending_alarms plugin hook triggered by the calendar module on keep-alive requests.
     * This will check for pending notifications and pass them to the client
     */
    public function pending_alarms($p)
    {
        $time = $p['time'] ?: time();
        if ($alarms = $this->driver->pending_alarms($time)) {
            foreach ($alarms as $alarm) {
                $alarm['id'] = 'cal:' . $alarm['id'];  // prefix ID with cal:
                $p['alarms'][] = $alarm;
            }
        }

        // get alarms for birthdays calendar
        if ($this->rc->config->get('calendar_contact_birthdays') && $this->rc->config->get('calendar_birthdays_alarm_type') == 'DISPLAY') {
            $cache = $this->rc->get_cache('calendar.birthdayalarms', 'db');

            foreach ($this->driver->get_default_driver()->load_birthday_events($time, $time + 86400 * 60) as $e) {
                $alarm = libcalendaring::get_next_alarm($e);

                // overwrite alarm time with snooze value (or null if dismissed)
                if ($dismissed = $cache->get($e['id']))
                    $alarm['time'] = $dismissed['notifyat'];

                // add to list if alarm is set
                if ($alarm && $alarm['time'] && $alarm['time'] <= $time) {
                    $e['id'] = 'cal:bday:' . $e['id'];
                    $e['notifyat'] = $alarm['time'];
                    $p['alarms'][] = $e;
                }
            }
        }

        return $p;
    }

    /**
     * Handler for alarm dismiss hook triggered by libcalendaring
     */
    public function dismiss_alarms($p)
    {

        foreach ((array)$p['ids'] as $id) {
            if (strpos($id, 'cal:bday:') === 0) {
                $p['success'] |= $this->driver->dismiss_birthday_alarm(substr($id, 9), $p['snooze']);
            } else if (strpos($id, 'cal:') === 0) {
                $p['success'] |= $this->driver->dismiss_alarm(substr($id, 4), $p['snooze']);
            }
        }

        return $p;
    }

    /**
     * Hook triggered when a contact is saved
     */
    function contact_update($p)
    {
        // clear birthdays calendar cache
        if (!empty($p['record']['birthday'])) {
            $cache = $this->rc->get_cache('calendar.birthdays', 'db');
            $cache->remove();
        }
    }

    /**
     * Handler for iCal feed requests
     */
    function ical_feed_export()
    {
        $session_exists = !empty($_SESSION['user_id']);

        // process HTTP auth info
        if (!empty($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $_POST['_user'] = $_SERVER['PHP_AUTH_USER']; // used for rcmail::autoselect_host()
            $auth = $this->rc->plugins->exec_hook('authenticate', array(
                'host' => $this->rc->autoselect_host(),
                'user' => trim($_SERVER['PHP_AUTH_USER']),
                'pass' => $_SERVER['PHP_AUTH_PW'],
                'cookiecheck' => true,
                'valid' => true,
            ));
            if ($auth['valid'] && !$auth['abort'])
                $this->rc->login($auth['user'], $auth['pass'], $auth['host']);
        }

        // require HTTP auth
        if (empty($_SESSION['user_id'])) {
            header('WWW-Authenticate: Basic realm="Roundcube Calendar"');
            header('HTTP/1.0 401 Unauthorized');
            exit;
        }

        // decode calendar feed hash
        $calhash = rcube_utils::get_input_value('_cal', rcube_utils::INPUT_GET);

        $suff_regex = '/\.([a-z0-9]{3,5})$/i';
        if (preg_match($suff_regex, $calhash, $m)) {
            $calhash = preg_replace($suff_regex, '', $calhash);
        }

        if (!strpos($calhash, ':'))
            $calhash = base64_decode($calhash);

        list($user, $_GET['source']) = explode(':', $calhash, 2);

        // sanity check user
        if ($this->rc->user->get_username() == $user) {
            $this->setup();
            $this->eventController->export_events(false);
        } else {
            header('HTTP/1.0 404 Not Found');
        }

        // don't save session data
        if (!$session_exists)
            session_destroy();
        exit;
    }

    /**
     *
     */
    function load_settings()
    {
        $this->lib->load_settings();
        $this->defaults += $this->lib->defaults;

        $settings = array();

        // configuration
        $settings['default_view']     = (string) $this->rc->config->get('calendar_default_view', $this->defaults['calendar_default_view']);
        $settings['timeslots']        = (int) $this->rc->config->get('calendar_timeslots', $this->defaults['calendar_timeslots']);
        $settings['first_day']        = (int) $this->rc->config->get('calendar_first_day', $this->defaults['calendar_first_day']);
        $settings['first_hour']       = (int) $this->rc->config->get('calendar_first_hour', $this->defaults['calendar_first_hour']);
        $settings['work_start']       = (int) $this->rc->config->get('calendar_work_start', $this->defaults['calendar_work_start']);
        $settings['work_end']         = (int) $this->rc->config->get('calendar_work_end', $this->defaults['calendar_work_end']);
        $settings['agenda_range']     = (int) $this->rc->config->get('calendar_agenda_range', $this->defaults['calendar_agenda_range']);
        $settings['agenda_sections']  = $this->rc->config->get('calendar_agenda_sections', $this->defaults['calendar_agenda_sections']);
        $settings['date_agenda']      = (string)$this->rc->config->get('calendar_date_agenda', $this->defaults['calendar_date_agenda']);
        $settings['event_coloring']   = (int) $this->rc->config->get('calendar_event_coloring', $this->defaults['calendar_event_coloring']);
        $settings['time_indicator']   = (int) $this->rc->config->get('calendar_time_indicator', $this->defaults['calendar_time_indicator']);
        $settings['invite_shared']    = (int) $this->rc->config->get('calendar_allow_invite_shared', $this->defaults['calendar_allow_invite_shared']);
        $settings['itip_notify']      = (int) $this->rc->config->get('calendar_itip_send_option', $this->defaults['calendar_itip_send_option']);
        $settings['show_weekno']      = (int) $this->rc->config->get('calendar_show_weekno', $this->defaults['calendar_show_weekno']);
        $settings['default_calendar'] = $this->rc->config->get('calendar_default_calendar');
        $settings['invitation_calendars'] = (bool) $this->rc->config->get('kolab_invitation_calendars', false);

        // 'table' view has been replaced by 'list' view
        if ($settings['default_view'] == 'table') {
            $settings['default_view'] = 'list';
        }

        // get user identity to create default attendee
        if ($this->ui->screen == 'calendar') {
            foreach ($this->rc->user->list_emails() as $rec) {
                $identity = $rec;
                $identity['emails'][] = $rec['email'];
                $settings['identities'][$rec['identity_id']] = $rec['email'];
            }
            $identity['emails'][] = $this->rc->user->get_username();
            $settings['identity'] = array('name' => $identity['name'], 'email' => strtolower($identity['email']), 'emails' => ';' . strtolower(join(';', $identity['emails'])));
        }

        // freebusy token authentication URL
        if (($url = $this->rc->config->get('calendar_freebusy_session_auth_url'))
            && ($uniqueid = $this->rc->config->get('kolab_uniqueid'))
        ) {
            if ($url === true) $url = '/freebusy';
            $url = rtrim(rcube_utils::resolve_url($url), '/ ');
            $url .= '/' . urlencode($this->rc->get_user_name());
            $url .= '/' . urlencode($uniqueid);

            $settings['freebusy_url'] = $url;
        }

        return $settings;
    }


    /**
     * Handler for attachments upload
     */
    public function attachment_upload()
    {
        $handler = new kolab_attachments_handler();
        $handler->attachment_upload(self::SESSION_KEY, 'cal-');
    }

    /**
     * Handler for attachments download/displaying
     */
    public function attachment_get()
    {
        // show loading page
        if (!empty($_GET['_preload'])) {
            return $this->lib->attachment_loading_page();
        }

        $event_id = rcube_utils::get_input_value('_event', rcube_utils::INPUT_GPC);
        $calendar = rcube_utils::get_input_value('_cal', rcube_utils::INPUT_GPC);
        $id       = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
        $rev      = rcube_utils::get_input_value('_rev', rcube_utils::INPUT_GPC);

        $event = array('id' => $event_id, 'calendar' => $calendar, 'rev' => $rev);
        $attachment = $this->driver->get_attachment($id, $event);

        // show part page
        if (!empty($_GET['_frame'])) {
            $this->lib->attachment = $attachment;
            $this->register_handler('plugin.attachmentframe', array($this->lib, 'attachment_frame'));
            $this->register_handler('plugin.attachmentcontrols', array($this->lib, 'attachment_header'));
            $this->rc->output->send('calendar.attachment');
        }
        // deliver attachment content
        else if ($attachment) {
            $attachment['body'] = $this->driver->get_attachment_body($id, $event);
            $this->lib->attachment_get($attachment);
        }

        // if we arrive here, the requested part was not found
        header('HTTP/1.1 404 Not Found');
        exit;
    }

    // TODO: extract to utils class
    /**
     * Determine whether the given event description is HTML formatted
     */
    public function is_html($event)
    {
        // check for opening and closing <html> or <body> tags
        return (preg_match('/<(html|body)(\s+[a-z]|>)/', $event['description'], $m) && strpos($event['description'], '</' . $m[1] . '>') > 0);
    }

    /**
     * Echo simple free/busy status text for the given user and time range
     */
    public function freebusy_status()
    {
        $email = rcube_utils::get_input_value('email', rcube_utils::INPUT_GPC);
        $start = rcube_utils::get_input_value('start', rcube_utils::INPUT_GPC);
        $end   = rcube_utils::get_input_value('end', rcube_utils::INPUT_GPC);

        // convert dates into unix timestamps
        if (!empty($start) && !is_numeric($start)) {
            $dts = new DateTime($start, $this->timezone);
            $start = $dts->format('U');
        }
        if (!empty($end) && !is_numeric($end)) {
            $dte = new DateTime($end, $this->timezone);
            $end = $dte->format('U');
        }

        if (!$start) $start = time();
        if (!$end) $end = $start + 3600;

        $fbtypemap = array(calendar::FREEBUSY_UNKNOWN => 'UNKNOWN', calendar::FREEBUSY_FREE => 'FREE', calendar::FREEBUSY_BUSY => 'BUSY', calendar::FREEBUSY_TENTATIVE => 'TENTATIVE', calendar::FREEBUSY_OOF => 'OUT-OF-OFFICE');
        $status = 'UNKNOWN';

        // if the backend has free-busy information
        $fblist = $this->driver->get_freebusy_list($email, $start, $end);

        if (is_array($fblist)) {
            $status = 'FREE';

            foreach ($fblist as $slot) {
                list($from, $to, $type) = $slot;
                if ($from < $end && $to > $start) {
                    $status = isset($type) && $fbtypemap[$type] ? $fbtypemap[$type] : 'BUSY';
                    break;
                }
            }
        }

        // let this information be cached for 5min
        $this->rc->output->future_expire_header(300);

        echo $status;
        exit;
    }

    /**
     * Return a list of free/busy time slots within the given period
     * Echo data in JSON encoding
     */
    public function freebusy_times()
    {
        $email = rcube_utils::get_input_value('email', rcube_utils::INPUT_GPC);
        $start = rcube_utils::get_input_value('start', rcube_utils::INPUT_GPC);
        $end   = rcube_utils::get_input_value('end', rcube_utils::INPUT_GPC);
        $interval  = intval(rcube_utils::get_input_value('interval', rcube_utils::INPUT_GPC));
        $strformat = $interval > 60 ? 'Ymd' : 'YmdHis';

        // convert dates into unix timestamps
        if (!empty($start) && !is_numeric($start)) {
            $dts = rcube_utils::anytodatetime($start, $this->timezone);
            $start = $dts ? $dts->format('U') : null;
        }
        if (!empty($end) && !is_numeric($end)) {
            $dte = rcube_utils::anytodatetime($end, $this->timezone);
            $end = $dte ? $dte->format('U') : null;
        }

        if (!$start) $start = time();
        if (!$end)   $end = $start + 86400 * 30;
        if (!$interval) $interval = 60;  // 1 hour

        if (!$dte) {
            $dts = new DateTime('@' . $start);
            $dts->setTimezone($this->timezone);
        }

        $fblist = $this->driver->get_freebusy_list($email, $start, $end);
        $slots  = '';

        // prepare freebusy list before use (for better performance)
        if (is_array($fblist)) {
            foreach ($fblist as $idx => $slot) {
                list($from, $to,) = $slot;

                // check for possible all-day times
                if (gmdate('His', $from) == '000000' && gmdate('His', $to) == '235959') {
                    // shift into the user's timezone for sane matching
                    $fblist[$idx][0] -= $this->gmt_offset;
                    $fblist[$idx][1] -= $this->gmt_offset;
                }
            }
        }

        // build a list from $start till $end with blocks representing the fb-status
        for ($s = 0, $t = $start; $t <= $end; $s++) {
            $t_end = $t + $interval * 60;
            $dt = new DateTime('@' . $t);
            $dt->setTimezone($this->timezone);

            // determine attendee's status
            if (is_array($fblist)) {
                $status = self::FREEBUSY_FREE;

                foreach ($fblist as $slot) {
                    list($from, $to, $type) = $slot;

                    if ($from < $t_end && $to > $t) {
                        $status = isset($type) ? $type : self::FREEBUSY_BUSY;
                        if ($status == self::FREEBUSY_BUSY)  // can't get any worse :-)
                            break;
                    }
                }
            } else {
                $status = self::FREEBUSY_UNKNOWN;
            }

            // use most compact format, assume $status is one digit/character
            $slots .= $status;
            $t = $t_end;
        }

        $dte = new DateTime('@' . $t_end);
        $dte->setTimezone($this->timezone);

        // let this information be cached for 5min
        $this->rc->output->future_expire_header(300);

        echo rcube_output::json_serialize(array(
            'email' => $email,
            'start' => $dts->format('c'),
            'end'   => $dte->format('c'),
            'interval' => $interval,
            'slots' => $slots,
        ));
        exit;
    }

    /**
     * Handler for printing calendars
     */
    public function print_view()
    {
        $title = $this->gettext('print');

        $view = rcube_utils::get_input_value('view', rcube_utils::INPUT_GPC);
        if (!in_array($view, array('agendaWeek', 'agendaDay', 'month', 'list')))
            $view = 'agendaDay';

        $this->rc->output->set_env('view', $view);

        if ($date = rcube_utils::get_input_value('date', rcube_utils::INPUT_GPC))
            $this->rc->output->set_env('date', $date);

        if ($range = rcube_utils::get_input_value('range', rcube_utils::INPUT_GPC))
            $this->rc->output->set_env('listRange', intval($range));

        if ($search = rcube_utils::get_input_value('search', rcube_utils::INPUT_GPC)) {
            $this->rc->output->set_env('search', $search);
            $title .= ' "' . $search . '"';
        }

        // Add JS to the page
        $this->ui->addJS();

        $this->register_handler('plugin.calendar_css', array($this->ui, 'calendar_css'));
        $this->register_handler('plugin.calendar_list', array($this->ui, 'calendar_list'));

        $this->rc->output->set_pagetitle($title);
        $this->rc->output->send('calendar.print');
    }

    /**
     * Update attendee properties on the given event object
     *
     * @param array The event object to be altered
     * @param array List of hash arrays each represeting an updated/added attendee
     */
    public static function merge_attendee_data(&$event, $attendees, $removed = null)
    {
        if (!empty($attendees) && !is_array($attendees[0])) {
            $attendees = array($attendees);
        }

        foreach ($attendees as $attendee) {
            $found = false;

            foreach ($event['attendees'] as $i => $candidate) {
                if ($candidate['email'] == $attendee['email']) {
                    $event['attendees'][$i] = $attendee;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $event['attendees'][] = $attendee;
            }
        }

        // filter out removed attendees
        if (!empty($removed)) {
            $event['attendees'] = array_filter($event['attendees'], function ($attendee) use ($removed) {
                return !in_array($attendee['email'], $removed);
            });
        }
    }


    /****  Resource management functions  ****/

    /**
     * Getter for the configured implementation of the resource directory interface
     */
    private function resources_directory()
    {
        if (is_object($this->resources_dir)) {
            return $this->resources_dir;
        }

        if ($driver_name = $this->rc->config->get('calendar_resources_driver')) {
            $driver_class = 'resources_driver_' . $driver_name;

            require_once($this->home . '/drivers/resources_driver.php');
            require_once($this->home . '/drivers/' . $driver_name . '/' . $driver_class . '.php');

            $this->resources_dir = new $driver_class($this);
        }

        return $this->resources_dir;
    }

    /**
     * Handler for resoruce autocompletion requests
     */
    public function resources_autocomplete()
    {
        $search = rcube_utils::get_input_value('_search', rcube_utils::INPUT_GPC, true);
        $sid    = rcube_utils::get_input_value('_reqid', rcube_utils::INPUT_GPC);
        $maxnum = (int)$this->rc->config->get('autocomplete_max', 15);
        $results = array();

        if ($directory = $this->resources_directory()) {
            foreach ($directory->load_resources($search, $maxnum) as $rec) {
                $results[]  = array(
                    'name'  => $rec['name'],
                    'email' => $rec['email'],
                    'type'  => $rec['_type'],
                );
            }
        }

        $this->rc->output->command('ksearch_query_results', $results, $search, $sid);
        $this->rc->output->send();
    }

    /**
     * Handler for load-requests for resource data
     */
    function resources_list()
    {
        $data = array();

        if ($directory = $this->resources_directory()) {
            foreach ($directory->load_resources() as $rec) {
                $data[] = $rec;
            }
        }

        $this->rc->output->command('plugin.resource_data', $data);
        $this->rc->output->send();
    }

    /**
     * Handler for requests loading resource owner information
     */
    function resources_owner()
    {
        if ($directory = $this->resources_directory()) {
            $id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
            $data = $directory->get_resource_owner($id);
        }

        $this->rc->output->command('plugin.resource_owner', $data);
        $this->rc->output->send();
    }

    /**
     * Deliver event data for a resource's calendar
     */
    function resources_calendar()
    {
        $events = array();

        if ($directory = $this->resources_directory()) {
            $events = $directory->get_resource_calendar(
                rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC),
                rcube_utils::get_input_value('start', rcube_utils::INPUT_GET),
                rcube_utils::get_input_value('end', rcube_utils::INPUT_GET)
            );
        }

        echo $this->eventController->encode($events);
        exit;
    }


    /****  Event invitation plugin hooks ****/

    /**
     * Find an event in user calendars
     */
    public function find_event($event, $mode)
    {
        // We search for writeable calendars in personal namespace by default
        $mode   = calendar_driver::FILTER_WRITEABLE | calendar_driver::FILTER_PERSONAL;
        $result = $this->driver->get_event($event, $mode);
        // ... now check shared folders if not found
        if (!$result) {
            $result = $this->driver->get_event($event, calendar_driver::FILTER_WRITEABLE | calendar_driver::FILTER_SHARED);
            if ($result) {
                $mode |= calendar_driver::FILTER_SHARED;
            }
        }

        return $result;
    }

    /**
     * Handler for URLs that allow an invitee to respond on his invitation mail
     */
    public function itip_attend_response($p)
    {
        $this->setup();

        if ($p['action'] == 'attend') {
            $this->ui->init();

            $this->rc->output->set_env('task', 'calendar');  // override some env vars
            $this->rc->output->set_env('refresh_interval', 0);
            $this->rc->output->set_pagetitle($this->gettext('calendar'));

            $itip  = $this->load_itip();
            $token = rcube_utils::get_input_value('_t', rcube_utils::INPUT_GPC);

            // read event info stored under the given token
            if ($invitation = $itip->get_invitation($token)) {
                $this->token = $token;
                $this->event = $invitation['event'];

                // show message about cancellation
                if ($invitation['cancelled']) {
                    $this->invitestatus = html::div('rsvp-status declined', $itip->gettext('eventcancelled'));
                }
                // save submitted RSVP status
                else if (!empty($_POST['rsvp'])) {
                    $status = null;
                    foreach (array('accepted', 'tentative', 'declined') as $method) {
                        if ($_POST['rsvp'] == $itip->gettext('itip' . $method)) {
                            $status = $method;
                            break;
                        }
                    }

                    // send itip reply to organizer
                    $invitation['event']['comment'] = rcube_utils::get_input_value('_comment', rcube_utils::INPUT_POST);
                    if ($status && $itip->update_invitation($invitation, $invitation['attendee'], strtoupper($status))) {
                        $this->invitestatus = html::div('rsvp-status ' . strtolower($status), $itip->gettext('youhave' . strtolower($status)));
                    } else
                        $this->rc->output->command('display_message', $this->gettext('errorsaving'), 'error', -1);

                    // if user is logged in...
                    if ($this->rc->user->ID) {
                        $invitation = $itip->get_invitation($token);

                        // save the event to his/her default calendar if not yet present
                        if (!$this->driver->get_event($this->event) && ($calendar = $this->get_default_calendar($invitation['event']['sensitivity']))) {
                            $invitation['event']['calendar'] = $calendar['id'];
                            if ($this->driver->new_event($invitation['event']))
                                $this->rc->output->command('display_message', $this->gettext(array('name' => 'importedsuccessfully', 'vars' => array('calendar' => $calendar['name']))), 'confirmation');
                        }
                    }
                }

                $this->register_handler('plugin.event_inviteform', array($this, 'itip_event_inviteform'));
                $this->register_handler('plugin.event_invitebox', array($this->ui, 'event_invitebox'));

                if (!$this->invitestatus) {
                    $this->itip->set_rsvp_actions(array('accepted', 'tentative', 'declined'));
                    $this->register_handler('plugin.event_rsvp_buttons', array($this->ui, 'event_rsvp_buttons'));
                }

                $this->rc->output->set_pagetitle($itip->gettext('itipinvitation') . ' ' . $this->event['title']);
            } else
                $this->rc->output->command('display_message', $this->gettext('itipinvalidrequest'), 'error', -1);

            $this->rc->output->send('calendar.itipattend');
        }
    }

    /**
     *
     */
    public function itip_event_inviteform($attrib)
    {
        $hidden = new html_hiddenfield(array('name' => "_t", 'value' => $this->token));
        return html::tag('form', array('action' => $this->rc->url(array('task' => 'calendar', 'action' => 'attend')), 'method' => 'post', 'noclose' => true) + $attrib) . $hidden->show();
    }


    /**
     * Import the full payload from a mail message attachment
     */
    public function mail_import_attachment()
    {
        $uid     = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mbox    = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $mime_id = rcube_utils::get_input_value('_part', rcube_utils::INPUT_POST);
        if (defined(RCUBE_CHARSET)) {
            $charset = RCUBE_CHARSET;
        } elseif (defined(RCUBE_CHARSET)) {
            $charset = RCUBE_CHARSET;
        } else {
            $charset = $this->rc->config->get('default_charset');
        }

        // establish imap connection
        $imap = $this->rc->get_storage();
        $imap->set_folder($mbox);

        if ($uid && $mime_id) {
            $part = $imap->get_message_part($uid, $mime_id);
            if ($part->ctype_parameters['charset'])
                $charset = $part->ctype_parameters['charset'];

            if ($part) {
                $events = $this->get_ical()->import($part, $charset);
            }
        }

        $success = $existing = 0;
        if (!empty($events)) {
            // find writeable calendar to store event
            $cal_id = !empty($_REQUEST['_calendar']) ? rcube_utils::get_input_value('_calendar', rcube_utils::INPUT_POST) : null;
            $calendars = $this->driver->list_calendars(calendar_driver::FILTER_PERSONAL);

            foreach ($events as $event) {
                // save to calendar
                $calendar = $calendars[$cal_id] ?: $this->get_default_calendar($event['sensitivity']);
                if ($calendar && $calendar['editable'] && $event['_type'] == 'event') {
                    $event['calendar'] = $calendar['id'];

                    if (!$this->driver->get_event($event['uid'], calendar_driver::FILTER_WRITEABLE)) {
                        $success += (bool)$this->driver->new_event($event);
                    } else {
                        $existing++;
                    }
                }
            }
        }

        if ($success) {
            $this->rc->output->command('display_message', $this->gettext(array(
                'name' => 'importsuccess',
                'vars' => array('nr' => $success),
            )), 'confirmation');
        } else if ($existing) {
            $this->rc->output->command('display_message', $this->gettext('importwarningexists'), 'warning');
        } else {
            $this->rc->output->command('display_message', $this->gettext('errorimportingevent'), 'error');
        }
    }

    /**
     * Read email message and return contents for a new event based on that message
     */
    public function mail_message2event()
    {
        $this->ui->init();
        $this->ui->addJS();
        $this->ui->init_templates();
        $this->ui->calendar_list(array(), true); // set env['calendars']

        $uid   = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GET);
        $mbox  = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GET);
        $event = array();

        // establish imap connection
        $imap    = $this->rc->get_storage();
        $message = new rcube_message($uid, $mbox);

        if ($message->headers) {
            $event['title']       = trim($message->subject);
            $event['description'] = trim($message->first_text_part());


            // add a reference to the email message
            if ($msgref = $this->driver->get_message_reference($message->headers, $mbox)) {
                $event['links'] = array($msgref);
            }
            // copy mail attachments to event
            else if ($message->attachments) {
                $eventid = 'cal-';
                if (!is_array($_SESSION[self::SESSION_KEY]) || $_SESSION[self::SESSION_KEY]['id'] != $eventid) {
                    $_SESSION[self::SESSION_KEY] = array();
                    $_SESSION[self::SESSION_KEY]['id'] = $eventid;
                    $_SESSION[self::SESSION_KEY]['attachments'] = array();
                }

                foreach ((array)$message->attachments as $part) {
                    $attachment = array(
                        'data' => $imap->get_message_part($uid, $part->mime_id, $part),
                        'size' => $part->size,
                        'name' => $part->filename,
                        'mimetype' => $part->mimetype,
                        'group' => $eventid,
                    );

                    $attachment = $this->rc->plugins->exec_hook('attachment_save', $attachment);

                    if ($attachment['status'] && !$attachment['abort']) {
                        $id = $attachment['id'];
                        $attachment['classname'] = rcube_utils::file2class($attachment['mimetype'], $attachment['name']);

                        // store new attachment in session
                        unset($attachment['status'], $attachment['abort'], $attachment['data']);
                        $_SESSION[self::SESSION_KEY]['attachments'][$id] = $attachment;

                        $attachment['id'] = 'rcmfile' . $attachment['id'];  // add prefix to consider it 'new'
                        $event['attachments'][] = $attachment;
                    }
                }
            }

            $this->rc->output->set_env('event_prop', $event);
        } else {
            $this->rc->output->command('display_message', $this->gettext('messageopenerror'), 'error');
        }

        $this->rc->output->send('calendar.dialog');
    }


    /**
     * Get a list of email addresses of the current user (from login and identities)
     */
    public function get_user_emails()
    {
        return $this->lib->get_user_emails();
    }


    /**
     * Build an absolute URL with the given parameters
     */
    public function get_url($param = array())
    {
        // PAMELA - Nouvelle URL
        $url = $_SERVER["REQUEST_URI"];
        $delm = '?';

        foreach ($param as $key => $val) {
            if ($val !== '' && $val !== null) {
                $par  = $key;
                $url .= $delm . urlencode($par) . '=' . urlencode($val);
                $delm = '&';
            }
        }

        return rcube_utils::resolve_url($url);
    }

    /**
     * PAMELA - Build an absolute URL with the given parameters
     */
    public function get_freebusy_url($param = array())
    {
        // PAMELA - Nouvelle URL
        $url = $_SERVER["REQUEST_URI"];
        $delm = '?';

        foreach ($param as $key => $val) {
            if ($val !== '' && $val !== null) {
                $par  = $key;
                $url .= $delm . urlencode($par) . '=' . urlencode($val);
                $delm = '&';
            }
        }

        return rcube_utils::resolve_url($url);
    }


    public function ical_feed_hash($source)
    {
        return base64_encode($this->rc->user->get_username() . ':' . $source);
    }

    /**
     * Handler for user_delete plugin hook
     */
    public function user_delete($args)
    {
        // delete itipinvitations entries related to this user
        $db = $this->rc->get_dbh();
        $table_itipinvitations = $db->table_name('itipinvitations', true);
        $db->query("DELETE FROM $table_itipinvitations WHERE `user_id` = ?", $args['user']->ID);

        if (!$this->driver->user_delete($args))
            return false;

        $this->setup();
        $this->load_driver();
        return $this->driver->user_delete($args);
    }

    /**
     * Magic getter for public access to protected members
     */
    public function __get($name)
    {
        switch ($name) {
            case 'ical':
                return $this->get_ical();
            case 'itip':
                return $this->load_itip();
            case 'driver':
                if (is_null($this->_driver)) {
                    $this->load_driver();
                }
                return $this->_driver;
        }

        //handle using controllers
        if (strpos($name, 'Controller') !== false) {
            return $this->controllersManager->get_controller($name);
        }

        return null;
    }
}
