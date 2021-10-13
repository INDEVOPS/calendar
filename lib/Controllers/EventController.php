<?php

namespace Calendar\Controllers;

use Calendar\Controllers\Controller;
use rcube;
use DateTime;
use DateInterval;
use rcube_plugin;
use rcube_output;
use libcalendaring;
use rcube_utils;
use ZipArchive;
use rcube_html2text;
use rcmail_output;
use html;
use calendar;
use calendar_recurrence;
use calendar_driver;
use rcube_mime;

/**
 * Class responsible for hooks/actions related to events 
 *  
 */
class EventController extends Controller
{
    const TASK = 'calendar';

    /**
     * @var array - roundcube actions to be created
     * @example  ' ["test"] - create "test" action and attach "$this->test()" method to it
     * @example  ' ["test" => "test_method"] - create "test" action and attach "$this->test_method()" method to it
     */
    const ACTIONS = [
        'count_events', 'load_events', 'export_events', 'event' => 'event_action_dispatcher', 'import_events' => 'import_events_from_file',
        'itip-status' => 'event_itip_status',

        'mailimportitip' => 'mail_import_itip',
        'mailimportattach' => 'mail_import_attachment',
        'dialog-ui' => 'mail_message2event',
        'itip-remove' => 'event_itip_remove',
        'itip-delegate' => 'mail_itip_delegate'
    ];

    // true if controller have message to display
    private bool $got_msg = false;

    // 1 for soft reload of event 2 for full calendar reload
    private int $should_reload = 0;

    /**
     * Handler for calendar/itip-remove requests
     */
    function event_itip_remove()
    {
        $success  = false;
        $uid      = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $instance = rcube_utils::get_input_value('_instance', rcube_utils::INPUT_POST);
        $savemode = rcube_utils::get_input_value('_savemode', rcube_utils::INPUT_POST);
        $listmode = calendar_driver::FILTER_WRITEABLE | calendar_driver::FILTER_PERSONAL;

        // search for event if only UID is given
        if ($event = $this->driver->get_event(array('uid' => $uid, '_instance' => $instance), $listmode)) {
            $event['_savemode'] = $savemode;
            $success = $this->driver->remove_event($event, true);
        }

        if ($success) {
            $this->rc->output->show_message('calendar.successremoval', 'confirmation');
        } else {
            $this->rc->output->show_message('calendar.errorsaving', 'error');
        }
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
                $events = $this->rc_plugin->ical->import($part, $charset);
            }
        }

        $success = $existing = 0;
        if (!empty($events)) {
            // find writeable calendar to store event
            $cal_id = !empty($_REQUEST['_calendar']) ? rcube_utils::get_input_value('_calendar', rcube_utils::INPUT_POST) : null;
            $calendars = $this->driver->list_calendars(calendar_driver::FILTER_PERSONAL);

            foreach ($events as $event) {
                // save to calendar
                $calendar = $calendars[$cal_id] ?: $this->rc_plugin->get_default_calendar($event['sensitivity']);
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
            $this->rc->output->command('display_message', $this->rc_plugin->gettext(array(
                'name' => 'importsuccess',
                'vars' => array('nr' => $success),
            )), 'confirmation');
        } else if ($existing) {
            $this->rc->output->command('display_message', $this->rc_plugin->gettext('importwarningexists'), 'warning');
        } else {
            $this->rc->output->command('display_message', $this->rc_plugin->gettext('errorimportingevent'), 'error');
        }
    }

    /**
     * Handler for calendar/itip-delegate requests
     */
    function mail_itip_delegate()
    {
        // forward request to mail_import_itip() with the right status
        $_POST['_status'] = $_REQUEST['_status'] = 'delegated';
        $this->mail_import_itip();
    }

    /**
     * Handler for requests fetching event counts for calendars
     */
    public function count_events()
    {
        // don't update session on these requests (avoiding race conditions)
        $this->rc->session->nowrite = true;

        $counts = 0;

        $start = rcube_utils::get_input_value('start', rcube_utils::INPUT_GET);
        if (!$start) {
            $start = new DateTime('today 00:00:00', $this->timezone);
            $start = $start->format('U');
        }

        $counts += $this->driver->count_events(
            rcube_utils::get_input_value('source', rcube_utils::INPUT_GET),
            $start,
            rcube_utils::get_input_value('end', rcube_utils::INPUT_GET)
        );

        $this->rc->output->command('plugin.update_counts', array('counts' => $counts));
    }


    /**
     * Handler for load-requests from fullcalendar
     * This will return pure JSON formatted output
     */
    public function load_events()
    {
        $start  = rcube_utils::get_input_value('start', rcube_utils::INPUT_GET);
        $end    = rcube_utils::get_input_value('end', rcube_utils::INPUT_GET);
        $query  = rcube_utils::get_input_value('q', rcube_utils::INPUT_GET);
        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_GET);

        //TODO: Extract to function
        if (!is_numeric($start) || strpos($start, 'T')) {
            $start = new DateTime($start, $this->timezone);
            $start = $start->getTimestamp();
        }
        if (!is_numeric($end) || strpos($end, 'T')) {
            $end = new DateTime($end, $this->timezone);
            $end = $end->getTimestamp();
        }

        $events = $this->driver->load_events($start, $end, $query, $source);
        echo $this->encode($events, !empty($query));
        exit;
    }
    // TODO: extract to utils class
    /**
     * Encode events as JSON
     *
     * @param  array  Events as array
     * @param  boolean Add CSS class names according to calendar and categories
     * @return string JSON encoded events
     */
    public function encode($events, $addcss = false)
    {
        $json = array();
        foreach ($events as $event) {
            $json[] = $this->serialize_event_for_ui($event, $addcss);
        }
        return rcube_output::json_serialize($json);
    }
    // TODO: Make class for $event
    /**
     * Convert an event object to be used on the client
     * 
     */
    public function serialize_event_for_ui($event, $addcss = false)
    {
        $lib = libcalendaring::get_instance();

        // compose a human readable strings for alarms_text and recurrence_text
        if ($event['valarms']) {
            $event['alarms_text'] = libcalendaring::alarms_text($event['valarms']);
            $event['valarms'] = libcalendaring::to_client_alarms($event['valarms']);
        }

        if ($event['recurrence']) {
            $event['recurrence_text'] = $lib->recurrence_text($event['recurrence']);
            $event['recurrence'] = $lib->to_client_recurrence($event['recurrence'], $event['allday']);
            unset($event['recurrence_date']);
        }

        foreach ((array)$event['attachments'] as $k => $attachment) {
            $event['attachments'][$k]['classname'] = rcube_utils::file2class($attachment['mimetype'], $attachment['name']);
        }


        // convert link URIs references into structs
        if (array_key_exists('links', $event)) {
            foreach ((array)$event['links'] as $i => $link) {
                if (strpos($link, 'imap://') === 0 && ($msgref = $this->driver->get_message_reference($link))) {
                    $event['links'][$i] = $msgref;
                }
            }
        }

        // check for organizer in attendees list
        $organizer = null;
        foreach ((array)$event['attendees'] as $i => $attendee) {
            if ($attendee['role'] == 'ORGANIZER') {
                $organizer = $attendee;
            }
            if ($attendee['status'] == 'DELEGATED' && $attendee['rsvp'] == false) {
                $event['attendees'][$i]['noreply'] = true;
            } else {
                unset($event['attendees'][$i]['noreply']);
            }
        }

        if ($organizer === null && !empty($event['organizer'])) {
            $organizer = $event['organizer'];
            $organizer['role'] = 'ORGANIZER';
            if (!is_array($event['attendees']))
                $event['attendees'] = array();
            array_unshift($event['attendees'], $organizer);
        }

        // Convert HTML description into plain text
        if ($this->rc_plugin->is_html($event)) {
            $h2t = new rcube_html2text($event['description'], false, true, 0);
            $event['description'] = trim($h2t->get_text());
        }

        // mapping url => vurl, allday => allDay because of the fullcalendar client script
        $event['vurl'] = $event['url'];
        $event['allDay'] = !empty($event['allday']);
        unset($event['url']);
        unset($event['allday']);

        $event['className'] = $event['className'] ? explode(' ', $event['className']) : array();

        if ($event['allDay']) {
            $event['end'] = $event['end']->add(new DateInterval('P1D'));
        }

        if ($_GET['mode'] == 'print') {
            $event['editable'] = false;
        }

        return array(
            '_id'   => $event['calendar'] . ':' . $event['id'],  // unique identifier for fullcalendar
            'start' => $this->rc_plugin->lib->adjust_timezone($event['start'], $event['allDay'])->format('c'),
            'end'   => $this->rc_plugin->lib->adjust_timezone($event['end'], $event['allDay'])->format('c'),
            // 'changed' might be empty for event recurrences (Bug #2185)
            'changed' => $event['changed'] ? $this->rc_plugin->lib->adjust_timezone($event['changed'])->format('c') : null,
            'created' => $event['created'] ? $this->rc_plugin->lib->adjust_timezone($event['created'])->format('c') : null,
            'title'       => strval($event['title']),
            'description' => strval($event['description']),
            'location'    => strval($event['location']),
        ) + $event;
    }

    /**
     * Construct the ics file for exporting events to iCalendar format;
     */
    public function export_events($terminate = true)
    {
        $start = rcube_utils::get_input_value('start', rcube_utils::INPUT_GET);
        $end   = rcube_utils::get_input_value('end', rcube_utils::INPUT_GET);

        if (!isset($start))
            $start = 'today -1 year';
        if (!is_numeric($start))
            $start = strtotime($start . ' 00:00:00');
        if (!$end)
            $end = 'today +10 years';
        if (!is_numeric($end))
            $end = strtotime($end . ' 23:59:59');

        $event_id    = rcube_utils::get_input_value('id', rcube_utils::INPUT_GET);
        $attachments = rcube_utils::get_input_value('attachments', rcube_utils::INPUT_GET);
        $calid = $filename = rcube_utils::get_input_value('source', rcube_utils::INPUT_GET);
        $calendars = $this->driver->list_calendars();
        $events = array();

        if ($calendars[$calid]) {
            $filename = $calendars[$calid]['name'] ? $calendars[$calid]['name'] : $calid;
            $filename = asciiwords(html_entity_decode($filename));  // to 7bit ascii
            if (!empty($event_id)) {
                if ($event = $this->driver->get_event(array('calendar' => $calid, 'id' => $event_id), 0, true)) {
                    if ($event['recurrence_id']) {
                        $event = $this->driver->get_event(array('calendar' => $calid, 'id' => $event['recurrence_id']), 0, true);
                    }
                    $events = array($event);
                    $filename = asciiwords($event['title']);
                    if (empty($filename))
                        $filename = 'event';
                }
            } else {
                $events = $this->driver->load_events($start, $end, null, $calid, 0);
                if (empty($filename))
                    $filename = $calid;
            }
        }

        header("Content-Type: text/calendar");
        header("Content-Disposition: inline; filename=" . $filename . '.ics');

        $this->rc_plugin->ical->export($events, '', true, $attachments ? array($this->driver, 'get_attachment_body') : null);

        if ($terminate)
            exit;
    }

    /**
     * Import events from file zip supported 
     */
    public function import_events_from_file()
    {
        // Upload progress update
        if (!empty($_GET['_progress'])) {
            $this->rc->upload_progress();
        }

        @set_time_limit(0);

        // process uploaded file if there is no error
        $err = $_FILES['_data']['error'];

        if (!$err && $_FILES['_data']['tmp_name']) {
            $calendar   = rcube_utils::get_input_value('calendar', rcube_utils::INPUT_GPC);
            $rangestart = $_REQUEST['_range'] ? date_create("now -" . intval($_REQUEST['_range']) . " months") : 0;

            // extract zip file
            if ($_FILES['_data']['type'] == 'application/zip') {
                $count = 0;
                if (class_exists('ZipArchive', false)) {
                    $zip = new ZipArchive();
                    if ($zip->open($_FILES['_data']['tmp_name'])) {
                        $randname = uniqid('zip-' . session_id(), true);
                        $tmpdir = slashify($this->rc->config->get('temp_dir', sys_get_temp_dir())) . $randname;
                        mkdir($tmpdir, 0700);

                        // extract each ical file from the archive and import it
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = $zip->getNameIndex($i);
                            if (preg_match('/\.ics$/i', $filename)) {
                                $tmpfile = $tmpdir . '/' . basename($filename);
                                if (copy('zip://' . $_FILES['_data']['tmp_name'] . '#' . $filename, $tmpfile)) {
                                    $count += $this->import_event_from_file($tmpfile, $calendar, $rangestart, $errors);
                                    unlink($tmpfile);
                                }
                            }
                        }

                        rmdir($tmpdir);
                        $zip->close();
                    } else {
                        $errors = 1;
                        $msg = 'Failed to open zip file.';
                    }
                } else {
                    $errors = 1;
                    $msg = 'Zip files are not supported for import.';
                }
            } else {
                // attempt to import teh uploaded file directly
                $count = $this->import_event_from_file($_FILES['_data']['tmp_name'], $calendar, $rangestart, $errors);
            }

            if ($count) {
                $this->rc->output->command('display_message', $this->rc_plugin->gettext(array('name' => 'importsuccess', 'vars' => array('nr' => $count))), 'confirmation');
                $this->rc->output->command('plugin.import_success', array('source' => $calendar, 'refetch' => true));
            } else if (!$errors) {
                $this->rc->output->command('display_message', $this->rc_plugin->gettext('importnone'), 'notice');
                $this->rc->output->command('plugin.import_success', array('source' => $calendar));
            } else {
                $this->rc->output->command('plugin.import_error', array('message' => $this->rc_plugin->gettext('importerror') . ($msg ? ': ' . $msg : '')));
            }
        } else {
            if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
                $msg = $this->rc->gettext(array('name' => 'filesizeerror', 'vars' => array(
                    'size' => $this->rc->show_bytes(parse_bytes(ini_get('upload_max_filesize')))
                )));
            } else {
                $msg = $this->rc->gettext('fileuploaderror');
            }

            $this->rc->output->command('plugin.import_error', array('message' => $msg));
        }

        $this->rc->output->send('iframe');
    }

    /**
     * Helper function to parse and import a single .ics file
     */
    private function import_event_from_file($filepath, $calendar, $rangestart, &$errors)
    {
        $user_email = $this->rc->user->get_username();

        $errors = !$this->rc_plugin->ical->fopen($filepath);
        $count = $i = 0;
        foreach ($this->rc_plugin->ical as $event) {
            // keep the browser connection alive on long import jobs
            if (++$i > 100 && $i % 100 == 0) {
                echo "<!-- -->";
                ob_flush();
            }

            // TODO: correctly handle recurring events which start before $rangestart
            if ($event['end'] < $rangestart && (!$event['recurrence'] || ($event['recurrence']['until'] && $event['recurrence']['until'] < $rangestart)))
                continue;

            $event['_owner'] = $user_email;
            $event['calendar'] = $calendar;
            if ($this->driver->new_event($event)) {
                $count++;
            } else {
                $errors++;
            }
        }

        return $count;
    }

    /**
     * Helper method sending iTip notifications after successful event updates
     */
    private function event_save_success(&$event, $old, $action, $success)
    {
        // $success is a new event ID
        if ($success !== true) {
            // send update notification on the main event
            if ($event['_savemode'] == 'future' && $event['_notify'] && $old['attendees'] && $old['recurrence_id']) {
                $master = $this->driver->get_event(array('id' => $old['recurrence_id'], 'calendar' => $old['calendar']), 0, true);
                unset($master['_instance'], $master['recurrence_date']);

                $sent = $this->notify_attendees($master, null, $action, $event['_comment'], false);
                if ($sent < 0)
                    $this->rc->output->show_message('calendar.errornotifying', 'error');

                $event['attendees'] = $master['attendees'];  // this tricks us into the next if clause
            }

            // delete old reference if saved as new
            if ($event['_savemode'] == 'future' || $event['_savemode'] == 'new') {
                $old = null;
            }

            $event['id'] = $success;
            $event['_savemode'] = 'all';
        }

        // send out notifications
        if ($event['_notify'] && ($event['attendees'] || $old['attendees'])) {
            $_savemode = $event['_savemode'];

            // send notification for the main event when savemode is 'all'
            if ($action != 'remove' && $_savemode == 'all' && ($event['recurrence_id'] || $old['recurrence_id'] || ($old && $old['id'] != $event['id']))) {
                $event['id'] = $event['recurrence_id'] ?: ($old['recurrence_id'] ?: $old['id']);
                $event = $this->driver->get_event($event, 0, true);
                unset($event['_instance'], $event['recurrence_date']);
            } else {
                // make sure we have the complete record
                $event = $action == 'remove' ? $old : $this->driver->get_event($event, 0, true);
            }

            $event['_savemode'] = $_savemode;

            if ($old) {
                $old['thisandfuture'] = $_savemode == 'future';
            }

            // only notify if data really changed (TODO: do diff check on client already)
            if (!$old || $action == 'remove' || self::event_diff($event, $old)) {
                $sent = $this->notify_attendees($event, $old, $action, $event['_comment']);
                if ($sent > 0)
                    $this->rc->output->show_message('calendar.itipsendsuccess', 'confirmation');
                else if ($sent < 0)
                    $this->rc->output->show_message('calendar.errornotifying', 'error');
            }
        }
    }

    /**
     * Compare two event objects and return differing properties
     *
     * @param array Event A
     * @param array Event B
     * @return array List of differing event properties
     */
    private function event_diff($a, $b)
    {
        $diff   = array();
        $ignore = array('changed' => 1, 'attachments' => 1);

        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            if (!$ignore[$key] && $key[0] != '_' && $a[$key] != $b[$key]) {
                $diff[] = $key;
            }
        }

        // only compare number of attachments
        if (count((array) $a['attachments']) != count((array) $b['attachments'])) {
            $diff[] = 'attachments';
        }

        return $diff;
    }

    /**
     * Send out an invitation/notification to all event attendees
     */
    private function notify_attendees($event, $old, $action = 'edit', $comment = null, $rsvp = null)
    {
        if ($action == 'remove' || ($event['status'] == 'CANCELLED' && $old['status'] != $event['status'])) {
            $event['cancelled'] = true;
            $is_cancelled = true;
        }

        if ($rsvp === null)
            $rsvp = !$old || $event['sequence'] > $old['sequence'];

        $emails = $this->rc_plugin->get_user_emails();
        $itip_notify = (int)$this->rc->config->get('calendar_itip_send_option', $this->defaults['calendar_itip_send_option']);

        // add comment to the iTip attachment
        $event['comment'] = $comment;

        // set a valid recurrence-id if this is a recurrence instance
        libcalendaring::identify_recurrence_instance($event);

        // compose multipart message using PEAR:Mail_Mime
        $method = $action == 'remove' ? 'CANCEL' : 'REQUEST';
        $message = $this->rc_plugin->itip->compose_itip_message($event, $method, $rsvp);

        // list existing attendees from $old event
        $old_attendees = array();
        foreach ((array)$old['attendees'] as $attendee) {
            $old_attendees[] = $attendee['email'];
        }

        // send to every attendee
        $sent = 0;
        $current = array();
        foreach ((array)$event['attendees'] as $attendee) {
            $current[] = strtolower($attendee['email']);

            // skip myself for obvious reasons
            if (!$attendee['email'] || in_array(strtolower($attendee['email']), $emails))
                continue;

            // skip if notification is disabled for this attendee
            if ($attendee['noreply'] && $itip_notify & 2)
                continue;

            // skip if this attendee has delegated and set RSVP=FALSE
            if ($attendee['status'] == 'DELEGATED' && $attendee['rsvp'] === false)
                continue;

            // which template to use for mail text
            $is_new = !in_array($attendee['email'], $old_attendees);
            $is_rsvp = $is_new || $event['sequence'] > $old['sequence'];
            $bodytext = $is_cancelled ? 'eventcancelmailbody' : ($is_new ? 'invitationmailbody' : 'eventupdatemailbody');
            $subject  = $is_cancelled ? 'eventcancelsubject'  : ($is_new ? 'invitationsubject' : ($event['title'] ? 'eventupdatesubject' : 'eventupdatesubjectempty'));

            $event['comment'] = $comment;

            // finally send the message
            if ($this->rc_plugin->itip->send_itip_message($event, $method, $attendee, $subject, $bodytext, $message, $is_rsvp))
                $sent++;
            else
                $sent = -100;
        }

        // TODO: on change of a recurring (main) event, also send updates to differing attendess of recurrence exceptions

        // send CANCEL message to removed attendees
        foreach ((array)$old['attendees'] as $attendee) {
            if ($attendee['role'] == 'ORGANIZER' || !$attendee['email'] || in_array(strtolower($attendee['email']), $current))
                continue;

            $vevent = $old;
            $vevent['cancelled'] = $is_cancelled;
            $vevent['attendees'] = array($attendee);
            $vevent['comment']   = $comment;
            if ($this->rc_plugin->itip->send_itip_message($vevent, 'CANCEL', $attendee, 'eventcancelsubject', 'eventcancelmailbody'))
                $sent++;
            else
                $sent = -100;
        }

        return $sent;
    }

    /**
     * Dispatcher for event actions initiated by the client
     */
    function event_action_dispatcher()
    {
        $action = rcube_utils::get_input_value('action', rcube_utils::INPUT_GPC);
        $event  = rcube_utils::get_input_value('e', rcube_utils::INPUT_POST, true);
        $success = false;
        $this->should_reload = 0;
        $this->got_msg = false;

        // force notify if hidden + active
        if ((int)$this->rc->config->get('calendar_itip_send_option', $this->defaults['calendar_itip_send_option']) === 1)
            $event['_notify'] = 1;

        // read old event data in order to find changes
        if (($event['_notify'] || $event['_decline']) && $action != 'new') {
            $old = $this->driver->get_event($event);

            // load main event if savemode is 'all' or if deleting 'future' events
            if (($event['_savemode'] == 'all' || ($event['_savemode'] == 'future' && $action == 'remove' && !$event['_decline'])) && $old['recurrence_id']) {
                $old['id'] = $old['recurrence_id'];
                $old = $this->driver->get_event($old);
            }
        }


        $success = $this->$action($event, $action, $old);


        // show confirmation/error message
        if (!$this->got_msg) {
            if ($success)
                $this->rc->output->show_message('successfullysaved', 'confirmation');
            else
                $this->rc->output->show_message('calendar.errorsaving', 'error');
        }

        // unlock client
        $this->rc->output->command('plugin.unlock_saving', $success);

        // update event object on the client or trigger a complete refresh if too complicated
        if ($this->should_reload && empty($_REQUEST['_framed'])) {
            $args = array('source' => $event['calendar']);
            if ($this->should_reload > 1)
                $args['refetch'] = true;
            else if ($success && $action != 'remove')
                $args['update'] = $this->serialize_event_for_ui($this->driver->get_event($event), true);
            $this->rc->output->command('plugin.refresh_calendar', $args);
        }
    }

    /**
     * Handler for calendar/itip-status requests
     */
    public function event_itip_status()
    {
        $data = rcube_utils::get_input_value('data', rcube_utils::INPUT_POST, true);
        $mode = null;

        // find local copy of the referenced event (in personal namespace)
        $existing  = $this->rc_plugin->find_event($data, $mode);
        $response  = $this->rc_plugin->itip->get_itip_status($data, $existing);
        $is_shared = (($mode & \calendar_driver::FILTER_SHARED) == \calendar_driver::FILTER_SHARED);
        // get a list of writeable calendars to save new events to
        if ((!$existing || $is_shared)
            && !$data['nosave']
            && ($response['action'] == 'rsvp' || $response['action'] == 'import')
        ) {
            $calendars       = $this->driver->list_calendars($mode);
            $calendar_select = new \html_select(array(
                'name'       => 'calendar',
                'id'         => 'itip-saveto',
                'is_escaped' => true,
                'class'      => 'form-control custom-select'
            ));
            $calendar_select->add('--', '');
            $numcals = 0;
            foreach ($calendars as $calendar) {
                if ($calendar['editable']) {
                    $calendar_select->add($calendar['name'], $calendar['id']);
                    $numcals++;
                }
            }
            if ($numcals < 1)
                $calendar_select = null;
        }

        if ($calendar_select) {
            $default_calendar = $this->rc_plugin->get_default_calendar($data['sensitivity'], $calendars);
            $response['select'] = \html::span('folder-select', $this->rc_plugin->gettext('saveincalendar') . '&nbsp;' .
                $calendar_select->show($is_shared ? $existing['calendar'] : $default_calendar['id']));
        } else if ($data['nosave']) {
            $response['select'] = \html::tag('input', array('type' => 'hidden', 'name' => 'calendar', 'id' => 'itip-saveto', 'value' => ''));
        }

        // render small agenda view for the respective day
        if ($data['method'] == 'REQUEST' && !empty($data['date']) && $response['action'] == 'rsvp') {
            $event_start = rcube_utils::anytodatetime($data['date']);
            $day_start   = new \Datetime(gmdate('Y-m-d 00:00', $data['date']), $this->rc_plugin->lib->timezone);
            $day_end     = new \Datetime(gmdate('Y-m-d 23:59', $data['date']), $this->rc_plugin->lib->timezone);

            // get events on that day from the user's personal calendars
            $calendars = $this->driver->list_calendars(calendar_driver::FILTER_PERSONAL);
            $events = $this->driver->load_events($day_start->format('U'), $day_end->format('U'), null, array_keys($calendars));
            usort($events, function ($a, $b) {
                return $a['start'] > $b['start'] ? 1 : -1;
            });

            $before = $after = array();
            foreach ($events as $event) {
                // TODO: skip events with free_busy == 'free' ?
                if (
                    $event['uid'] == $data['uid']
                    || $event['end'] < $day_start || $event['start'] > $day_end
                    || $event['status'] == 'CANCELLED'
                    || (!empty($event['className']) && strpos($event['className'], 'declined') !== false)
                ) {
                    continue;
                }

                if ($event['start'] < $event_start)
                    $before[] = $this->mail_agenda_event_row($event);
                else
                    $after[] = $this->mail_agenda_event_row($event);
            }

            $response['append'] = array(
                'selector' => '.calendar-agenda-preview',
                'replacements' => array(
                    '%before%' => !empty($before) ? join("\r\n", array_slice($before,  -3)) : html::div('event-row no-event', $this->rc_plugin->gettext('noearlierevents')),
                    '%after%'  => !empty($after)  ? join("\r\n", array_slice($after, 0, 3)) : html::div('event-row no-event', $this->rc_plugin->gettext('nolaterevents')),
                ),
            );
        }

        $this->rc->output->command('plugin.update_itip_object_status', $response);
    }

    /**
     * Prepare html div containing basic info about event
     *
     * @param array $event
     * @param string $class - css class
     * @return string - html code
     */
    private function mail_agenda_event_row($event, $class = ''): string
    {
        $time = $event['allday'] ? $this->rc_plugin->gettext('all-day') :
            $this->rc->format_date($event['start'], $this->rc->config->get('time_format')) . ' - ' .
            $this->rc->format_date($event['end'], $this->rc->config->get('time_format'));

        return html::div(
            rtrim('event-row ' . ($class ?: $event['className'])),
            html::span('event-date', $time) .
                html::span('event-title', rcube::Q($event['title']))
        );
    }

    /**
     * Create new event using driver
     *
     * @param array $event
     * @param string $action
     * @return mixed False on fail, new event ID on true
     */
    private function new($event, $action)
    {
        $event['uid'] = $this->generate_uid();

        if (!$this->write_preprocess($event, $action)) {
            $this->got_msg = true;
        } else if ($success = $this->driver->new_event($event)) {
            $event['id'] = $event['uid'];
            $event['_savemode'] = 'all';
            $this->cleanup_event($event);
            $this->event_save_success($event, null, $action, true);
        }

        return $success;
    }

    /**
     * Base function for editing event
     *
     * @return bool True if operation was successful
     */
    private function edit_event($event, $action, $old_event): bool
    {
        $success = false;
        if (!$this->write_preprocess($event, $action)) {
            $this->got_msg = true;
        } else if ($success = $this->driver->edit_event($event)) {
            $this->cleanup_event($event);
            $this->event_save_success($event, $old_event, $action, $success);
        }

        return $success;
    }

    /**
     * Edit event using driver
     *
     * @param array $event
     * @param string $action
     * @return bool True if operation was successful
     */
    private function edit($event, $action, $old_event): bool
    {
        $success = $this->edit_event($event, $action, $old_event);
        $this->should_reload = $success && ($event['recurrence'] || $event['_savemode'] || $event['_fromcalendar']) ? 2 : 1;

        return $success;
    }

    /**
     * Change size of event
     *
     * @param array $event
     * @param string $action
     * @return bool True if operation was successful
     */
    private function resize($event, $action, $old_event): bool
    {
        $success = $this->edit_event($event, $action, $old_event);
        $this->should_reload = $success && $event['_savemode'] ? 2 : 1;

        return $success;
    }

    /**
     * Move event
     *
     * @param array $event
     * @param string $action
     * @param array $old_event
     * @return bool True if operation was successful
     */
    private function move($event, $action, $old_event): bool
    {
        return $this->resize($event, $action, $old_event);
    }

    /**
     * Remove existing event
     * 
     * @param array $event
     * @param string $action
     * @param array $old_event
     * @return bool True if operation was successful
     */
    public function remove($event, $action, $old_event): bool
    {
        // remove previous deletes
        $undo_time = $this->driver->undelete ? $this->rc->config->get('undo_timeout', 0) : 0;

        // search for event if only UID is given
        if (!isset($event['calendar']) && $event['uid']) {
            $event = $this->driver->get_event($event, calendar_driver::FILTER_WRITEABLE);
            if (!$event) {
                return false;
            }
            $undo_time = 0;
        }

        // Note: the driver is responsible for setting $_SESSION['calendar_event_undo']
        //       containing 'ts' and 'data' elements
        $success = $this->driver->remove_event($event, $undo_time < 1);
        $this->should_reload = (!$success || $event['_savemode']) ? 2 : 1;

        if ($undo_time > 0 && $success) {
            // display message with Undo link.
            if ($undo_time > 0) {
                $msg = html::span(null, $this->rc_plugin->gettext('successremoval'))
                    . ' ' . html::a(array('onclick' => sprintf(
                        "%s.http_request('event', 'action=undo', %s.display_message('', 'loading'))",
                        rcmail_output::JS_OBJECT_NAME,
                        rcmail_output::JS_OBJECT_NAME
                    )), $this->rc_plugin->gettext('undo'));
                $this->rc->output->show_message($msg, 'confirmation', null, true, $undo_time);
            } else {
                $this->rc->output->show_message('calendar.successremoval', 'confirmation');
            }
            $this->got_msg = true;
        }

        // send cancellation for the main event
        if ($event['_savemode'] == 'all') {
            unset($old_event['_instance'], $old_event['recurrence_date'], $old_event['recurrence_id']);
        }
        // send an update for the main event's recurrence rule instead of a cancellation message
        else if ($event['_savemode'] == 'future' && $success !== false && $success !== true) {
            $event['_savemode'] = 'all';  // force event_save_success() to load master event
            $action = 'edit';
            $success = true;
        }

        // send iTIP reply that participant has declined the event
        if ($success && $event['_decline']) {
            $emails = $this->rc_plugin->get_user_emails();
            foreach ($old_event['attendees'] as $i => $attendee) {
                if ($attendee['role'] == 'ORGANIZER')
                    $organizer = $attendee;
                else if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
                    $old_event['attendees'][$i]['status'] = 'DECLINED';
                    $reply_sender = $attendee['email'];
                }
            }

            if ($event['_savemode'] == 'future' && $event['id'] != $old_event['id']) {
                $old_event['thisandfuture'] = true;
            }

            $this->rc_plugin->itip->set_sender_email($reply_sender);
            if ($organizer && $this->rc_plugin->itip->send_itip_message($old_event, 'REPLY', $organizer, 'itipsubjectdeclined', 'itipmailbodydeclined'))
                $this->rc->output->command('display_message', $this->rc_plugin->gettext(array('name' => 'sentresponseto', 'vars' => array('mailto' => $organizer['name'] ? $organizer['name'] : $organizer['email']))), 'confirmation');
            else
                $this->rc->output->command('display_message', $this->rc_plugin->gettext('itipresponseerror'), 'error');
        } else if ($success) {
            $this->event_save_success($event, $old_event, $action, $success);
        }

        return $success;
    }

    /**
     * Undo removal of event
     *
     * @param array $event
     * @param string $action
     * @return bool True if operation was successful
     */
    private function undo($event, $action): bool
    {
        $success = false;
        if ($event = $_SESSION['calendar_event_undo']['data'])
            $success = $this->driver->restore_event($event);

        if ($success) {
            $this->rc->session->remove('calendar_event_undo');
            $this->rc->output->show_message('calendar.successrestore', 'confirmation');
            $this->got_msg = true;
            $this->should_reload  = 2;
        }

        return $success;
    }

    /**
     * Send response to invitation.
     * 
     * @param array $event
     * @return bool True if operation was successful 
     */
    private function rsvp($event): bool
    {
        $success = false;
        $itip_sending  = $this->rc->config->get('calendar_itip_send_option', $this->defaults['calendar_itip_send_option']);
        $status        = rcube_utils::get_input_value('status', rcube_utils::INPUT_POST);
        $attendees     = rcube_utils::get_input_value('attendees', rcube_utils::INPUT_POST);
        $reply_comment = $event['comment'];

        $this->write_preprocess($event, 'edit');
        $ev = $this->driver->get_event($event);
        $ev['attendees'] = $event['attendees'];
        $ev['free_busy'] = $event['free_busy'];
        $ev['_savemode'] = $event['_savemode'];
        $ev['comment']   = $reply_comment;

        // send invitation to delegatee + add it as attendee
        if ($status == 'delegated' && $event['to']) {
            if ($this->rc_plugin->itip->delegate_to($ev, $event['to'], (bool)$event['rsvp'], $attendees)) {
                $this->rc->output->show_message('calendar.itipsendsuccess', 'confirmation');
                $noreply = false;
            }
        }

        $event = $ev;
        //FIXME: Need troubleshooting
        // compose a list of attendees affected by this change
        $updated_attendees = array_filter(array_map(function ($j) use ($event) {
            return $event['attendees'][$j];
        }, $attendees));

        if ($success = $this->driver->edit_rsvp($event, $status, $updated_attendees)) {
            $noreply = rcube_utils::get_input_value('noreply', rcube_utils::INPUT_GPC);
            $noreply = intval($noreply) || $status == 'needs-action' || $itip_sending === 0;
            $this->should_reload  = $event['calendar'] != $ev['calendar'] || $event['recurrence'] ? 2 : 1;
            $organizer = null;
            $emails = $this->rc_plugin->get_user_emails();

            foreach ($event['attendees'] as $i => $attendee) {
                if ($attendee['role'] == 'ORGANIZER') {
                    $organizer = $attendee;
                } else if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
                    $reply_sender = $attendee['email'];
                }
            }

            if (!$noreply) {
                $this->rc_plugin->itip->set_sender_email($reply_sender);
                $event['thisandfuture'] = $event['_savemode'] == 'future';
                if ($organizer && $this->rc_plugin->itip->send_itip_message($event, 'REPLY', $organizer, 'itipsubject' . $status, 'itipmailbody' . $status))
                    $this->rc->output->command('display_message', $this->rc_plugin->gettext(array('name' => 'sentresponseto', 'vars' => array('mailto' => $organizer['name'] ? $organizer['name'] : $organizer['email']))), 'confirmation');
                else
                    $this->rc->output->command('display_message', $this->rc_plugin->gettext('itipresponseerror'), 'error');
            }

            // refresh all calendars
            if ($event['calendar'] != $ev['calendar']) {
                $this->rc->output->command('plugin.refresh_calendar', array('source' => null, 'refetch' => true));
                $this->should_reload = 0;
            }
        }

        return $success;
    }

    /**
     * Show event
     *
     * @param array $event
     * @return bool True if operation was successful 
     */
    private function show($event): bool
    {
        $event = $this->driver->get_event_revison($event, $event['rev']);
        if ($event) {
            $this->rc->output->command('plugin.event_show_revision', $event);
        } else {
            $this->rc->output->command('display_message', $this->rc_plugin->gettext('objectnotfound'), 'error');
        }
        $this->got_msg = true;
        $this->should_reload = 0;

        return !is_null($event);
    }

    /**
     * Restore event
     * 
     * @param array $event
     * @return bool True if operation was successful 
     */
    private function restore($event): bool
    {
        $success = $this->driver->restore_event_revision($event, $event['rev']);

        if ($success) {
            $retrieved_event = $this->driver->get_event($event);
            $this->should_reload = $retrieved_event['recurrence'] ? 2 : 1;
            $this->rc->output->command(
                'display_message',
                $this->rc_plugin->gettext(array(
                    'name' => 'objectrestoresuccess', 'vars' => array('rev' => $event['rev'])
                )),
                'confirmation'
            );

            $this->rc->output->command('plugin.close_history_dialog');
        } else {
            $this->rc->output->command('display_message', $this->rc_plugin->gettext('objectrestoreerror'), 'error');
            $this->should_reload = 0;
        }
        $this->got_msg = true;

        return $success;
    }
    /**
     * Generate a unique identifier for an event
     */
    public function generate_uid()
    {
        return strtoupper(md5(time() . uniqid(rand())) . '-' . substr(md5($this->rc->user->get_username()), 0, 16));
    }

    /**
     * Prepares new/edited event properties before save
     */
    private function write_preprocess(&$event, $action)
    {
        // Remove double timezone specification (T2313)
        $event['start'] = preg_replace('/\s*\(.*\)/', '', $event['start']);
        $event['end']   = preg_replace('/\s*\(.*\)/', '', $event['end']);

        // convert dates into DateTime objects in user's current timezone
        if (!is_object($event['start']))
            $event['start'] = new DateTime($event['start'], $this->timezone);
        if (!is_object($event['end']))
            $event['end'] = new DateTime($event['end'], $this->timezone);
        $event['allday'] = !empty($event['allDay']);
        unset($event['allDay']);

        // start/end is all we need for 'move' action (#1480)
        if ($action == 'move') {
            return true;
        }

        // convert the submitted recurrence settings
        if (is_array($event['recurrence'])) {
            $lib = libcalendaring::get_instance();
            $event['recurrence'] = $lib->from_client_recurrence($event['recurrence'], $event['start']);

            // align start date with the first occurrence
            if (
                !empty($event['recurrence']) && !empty($event['syncstart'])
                && (empty($event['_savemode']) || $event['_savemode'] == 'all')
            ) {
                $next = $this->find_first_occurrence($event);

                if (!$next) {
                    $this->rc->output->show_message('calendar.recurrenceerror', 'error');
                    return false;
                } else if ($event['start'] != $next) {
                    $diff = $event['start']->diff($event['end'], true);

                    $event['start'] = $next;
                    $event['end']   = clone $next;
                    $event['end']->add($diff);
                }
            }
        }

        // convert the submitted alarm values
        if ($event['valarms']) {
            $event['valarms'] = libcalendaring::from_client_alarms($event['valarms']);
        }

        $attachments = array();
        $eventid     = 'cal-' . $event['id'];

        if (is_array($_SESSION[calendar::SESSION_KEY]) && $_SESSION[calendar::SESSION_KEY]['id'] == $eventid) {
            if (!empty($_SESSION[calendar::SESSION_KEY]['attachments'])) {
                foreach ($_SESSION[calendar::SESSION_KEY]['attachments'] as $id => $attachment) {
                    if (is_array($event['attachments']) && in_array($id, $event['attachments'])) {
                        $attachments[$id] = $this->rc->plugins->exec_hook('attachment_get', $attachment);
                    }
                }
            }
        }

        $event['attachments'] = $attachments;

        // convert link references into simple URIs
        if (array_key_exists('links', $event)) {
            $event['links'] = array_map(function ($link) {
                return is_array($link) ? $link['uri'] : strval($link);
            }, (array)$event['links']);
        }

        // check for organizer in attendees
        if ($action == 'new' || $action == 'edit') {
            if (!$event['attendees'])
                $event['attendees'] = array();

            $emails = $this->rc_plugin->get_user_emails();
            $organizer = $owner = false;
            foreach ((array)$event['attendees'] as $i => $attendee) {
                if ($attendee['role'] == 'ORGANIZER')
                    $organizer = $i;
                if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails))
                    $owner = $i;
                if (!isset($attendee['rsvp']))
                    $event['attendees'][$i]['rsvp'] = true;
                else if (is_string($attendee['rsvp']))
                    $event['attendees'][$i]['rsvp'] = $attendee['rsvp'] == 'true' || $attendee['rsvp'] == '1';
            }

            if (!empty($event['_identity'])) {
                $identity = $this->rc->user->get_identity($event['_identity']);
            }

            // set new organizer identity
            if ($organizer !== false && $identity) {
                $event['attendees'][$organizer]['name'] = $identity['name'];
                $event['attendees'][$organizer]['email'] = $identity['email'];
            }
            // set owner as organizer if yet missing
            else if ($organizer === false && $owner !== false) {
                $event['attendees'][$owner]['role'] = 'ORGANIZER';
                unset($event['attendees'][$owner]['rsvp']);
            }
            // fallback to the selected identity
            else if ($organizer === false && $identity) {
                $event['attendees'][] = array(
                    'role'  => 'ORGANIZER',
                    'name'  => $identity['name'],
                    'email' => $identity['email'],
                );
            }
        }

        // mapping url => vurl because of the fullcalendar client script
        if (array_key_exists('vurl', $event)) {
            $event['url'] = $event['vurl'];
            unset($event['vurl']);
        }

        return true;
    }

    /**
     * Handler for POST request to import an event attached to a mail message
     */
    public function mail_import_itip()
    {
        $itip_sending = $this->rc->config->get('calendar_itip_send_option', $this->defaults['calendar_itip_send_option']);

        $uid     = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mbox    = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $mime_id = rcube_utils::get_input_value('_part', rcube_utils::INPUT_POST);
        $status  = rcube_utils::get_input_value('_status', rcube_utils::INPUT_POST);
        $delete  = intval(rcube_utils::get_input_value('_del', rcube_utils::INPUT_POST));
        $noreply = intval(rcube_utils::get_input_value('_noreply', rcube_utils::INPUT_POST));
        $noreply = $noreply || $status == 'needs-action' || $itip_sending === 0;
        $instance = rcube_utils::get_input_value('_instance', rcube_utils::INPUT_POST);
        $savemode = rcube_utils::get_input_value('_savemode', rcube_utils::INPUT_POST);
        $comment  = rcube_utils::get_input_value('_comment', rcube_utils::INPUT_POST);

        $error_msg = $this->rc_plugin->gettext('errorimportingevent');
        $success   = false;

        if ($status == 'delegated') {
            $delegates = rcube_mime::decode_address_list(rcube_utils::get_input_value('_to', rcube_utils::INPUT_POST, true), 1, false);
            $delegate  = reset($delegates);

            if (empty($delegate) || empty($delegate['mailto'])) {
                $this->rc->output->command('display_message', $this->rc->gettext('libcalendaring.delegateinvalidaddress'), 'error');
                return;
            }
        }

        // successfully parsed events?
        if ($event = $this->rc_plugin->lib->mail_get_itip_object($mbox, $uid, $mime_id, 'event')) {
            // forward iTip request to delegatee
            if ($delegate) {
                $rsvpme = rcube_utils::get_input_value('_rsvp', rcube_utils::INPUT_POST);

                $event['comment'] = $comment;

                if ($this->rc_plugin->itip->delegate_to($event, $delegate, !empty($rsvpme))) {
                    $this->rc->output->show_message('calendar.itipsendsuccess', 'confirmation');
                } else {
                    $this->rc->output->command('display_message', $this->rc_plugin->gettext('itipresponseerror'), 'error');
                }

                unset($event['comment']);

                // the delegator is set to non-participant, thus save as non-blocking
                $event['free_busy'] = 'free';
            }

            $mode = calendar_driver::FILTER_PERSONAL
                | calendar_driver::FILTER_SHARED
                | calendar_driver::FILTER_WRITEABLE;

            // find writeable calendar to store event
            $cal_id    = rcube_utils::get_input_value('_folder', rcube_utils::INPUT_POST);
            $dontsave  = $cal_id === '' && $event['_method'] == 'REQUEST';
            $calendars = $this->driver->list_calendars($mode);
            $calendar  = $calendars[$cal_id];

            // select default calendar except user explicitly selected 'none'
            if (!$calendar && !$dontsave)
                $calendar = $this->rc_plugin->get_default_calendar($event['sensitivity'], $calendars);

            $metadata = array(
                'uid'       => $event['uid'],
                '_instance' => $event['_instance'],
                'changed'   => is_object($event['changed']) ? $event['changed']->format('U') : 0,
                'sequence'  => intval($event['sequence']),
                'fallback'  => strtoupper($status),
                'method'    => $event['_method'],
                'task'      => 'calendar',
            );

            // update my attendee status according to submitted method
            if (!empty($status)) {
                $organizer = null;
                $emails = $this->rc_plugin->get_user_emails();
                foreach ($event['attendees'] as $i => $attendee) {
                    if ($attendee['role'] == 'ORGANIZER') {
                        $organizer = $attendee;
                    } else if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
                        $event['attendees'][$i]['status'] = strtoupper($status);
                        if (!in_array($event['attendees'][$i]['status'], array('NEEDS-ACTION', 'DELEGATED')))
                            $event['attendees'][$i]['rsvp'] = false;  // unset RSVP attribute

                        $metadata['attendee'] = $attendee['email'];
                        $metadata['rsvp'] = $attendee['role'] != 'NON-PARTICIPANT';
                        $reply_sender = $attendee['email'];
                        $event_attendee = $attendee;
                    }
                }

                // add attendee with this user's default identity if not listed
                if (!$reply_sender) {
                    $sender_identity = $this->rc->user->list_emails(true);
                    $event['attendees'][] = array(
                        'name'   => $sender_identity['name'],
                        'email'  => $sender_identity['email'],
                        'role'   => 'OPT-PARTICIPANT',
                        'status' => strtoupper($status),
                    );
                    $metadata['attendee'] = $sender_identity['email'];
                }
            }

            // save to calendar
            if ($calendar && $calendar['editable']) {
                // check for existing event with the same UID
                $existing = $this->rc_plugin->find_event($event, $mode);

                // we'll create a new copy if user decided to change the calendar
                if ($existing && $cal_id && $calendar && $calendar['id'] != $existing['calendar']) {
                    $existing = null;
                }

                if ($existing) {
                    $calendar = $calendars[$existing['calendar']];

                    // forward savemode for correct updates of recurring events
                    $existing['_savemode'] = $savemode ?: $event['_savemode'];

                    // only update attendee status
                    if ($event['_method'] == 'REPLY') {
                        // try to identify the attendee using the email sender address
                        $existing_attendee        = -1;
                        $existing_attendee_emails = array();

                        foreach ($existing['attendees'] as $i => $attendee) {
                            $existing_attendee_emails[] = $attendee['email'];
                            if ($this->itip->compare_email($attendee['email'], $event['_sender'], $event['_sender_utf'])) {
                                $existing_attendee = $i;
                            }
                        }

                        $event_attendee   = null;
                        $update_attendees = array();

                        foreach ($event['attendees'] as $attendee) {
                            if ($this->itip->compare_email($attendee['email'], $event['_sender'], $event['_sender_utf'])) {
                                $event_attendee       = $attendee;
                                $update_attendees[]   = $attendee;
                                $metadata['fallback'] = $attendee['status'];
                                $metadata['attendee'] = $attendee['email'];
                                $metadata['rsvp']     = $attendee['rsvp'] || $attendee['role'] != 'NON-PARTICIPANT';

                                if ($attendee['status'] != 'DELEGATED') {
                                    break;
                                }
                            }
                            // also copy delegate attendee
                            else if (
                                !empty($attendee['delegated-from'])
                                && $this->itip->compare_email($attendee['delegated-from'], $event['_sender'], $event['_sender_utf'])
                            ) {
                                $update_attendees[] = $attendee;
                                if (!in_array_nocase($attendee['email'], $existing_attendee_emails)) {
                                    $existing['attendees'][] = $attendee;
                                }
                            }
                        }

                        // if delegatee has declined, set delegator's RSVP=True
                        if ($event_attendee && $event_attendee['status'] == 'DECLINED' && $event_attendee['delegated-from']) {
                            foreach ($existing['attendees'] as $i => $attendee) {
                                if ($attendee['email'] == $event_attendee['delegated-from']) {
                                    $existing['attendees'][$i]['rsvp'] = true;
                                    break;
                                }
                            }
                        }

                        // Accept sender as a new participant (different email in From: and the iTip)
                        // Use ATTENDEE entry from the iTip with replaced email address
                        if (!$event_attendee) {
                            // remove the organizer
                            $itip_attendees = array_filter($event['attendees'], function ($item) {
                                return $item['role'] != 'ORGANIZER';
                            });

                            // there must be only one attendee
                            if (is_array($itip_attendees) && count($itip_attendees) == 1) {
                                $event_attendee          = $itip_attendees[key($itip_attendees)];
                                $event_attendee['email'] = $event['_sender'];
                                $update_attendees[]      = $event_attendee;
                                $metadata['fallback']    = $event_attendee['status'];
                                $metadata['attendee']    = $event_attendee['email'];
                                $metadata['rsvp']        = $event_attendee['rsvp'] || $event_attendee['role'] != 'NON-PARTICIPANT';
                            }
                        }

                        // found matching attendee entry in both existing and new events
                        if ($existing_attendee >= 0 && $event_attendee) {
                            $existing['attendees'][$existing_attendee] = $event_attendee;
                            $success = $this->driver->update_attendees($existing, $update_attendees);
                        }
                        // update the entire attendees block
                        else if (($event['sequence'] >= $existing['sequence'] || $event['changed'] >= $existing['changed']) && $event_attendee) {
                            $existing['attendees'][] = $event_attendee;
                            $success = $this->driver->update_attendees($existing, $update_attendees);
                        } else if (!$event_attendee) {
                            $error_msg = $this->rc_plugin->gettext('errorunknownattendee');
                        } else {
                            $error_msg = $this->rc_plugin->gettext('newerversionexists');
                        }
                    }
                    // delete the event when declined (#1670)
                    else if ($status == 'declined' && $delete) {
                        $deleted = $this->driver->remove_event($existing, true);
                        $success = true;
                    }
                    // import the (newer) event
                    else if ($event['sequence'] >= $existing['sequence'] || $event['changed'] >= $existing['changed']) {
                        $event['id'] = $existing['id'];
                        $event['calendar'] = $existing['calendar'];

                        // merge attendees status
                        // e.g. preserve my participant status for regular updates
                        $this->rc_plugin->lib->merge_attendees($event, $existing, $status);

                        // set status=CANCELLED on CANCEL messages
                        if ($event['_method'] == 'CANCEL')
                            $event['status'] = 'CANCELLED';

                        // update attachments list, allow attachments update only on REQUEST (#5342)
                        if ($event['_method'] == 'REQUEST')
                            $event['deleted_attachments'] = true;
                        else
                            unset($event['attachments']);

                        // show me as free when declined (#1670)
                        if ($status == 'declined' || $event['status'] == 'CANCELLED' || $event_attendee['role'] == 'NON-PARTICIPANT')
                            $event['free_busy'] = 'free';

                        $success = $this->driver->edit_event($event);
                    } else if (!empty($status)) {
                        $existing['attendees'] = $event['attendees'];
                        if ($status == 'declined' || $event_attendee['role'] == 'NON-PARTICIPANT')  // show me as free when declined (#1670)
                            $existing['free_busy'] = 'free';
                        $success = $this->driver->edit_event($existing);
                    } else
                        $error_msg = $this->rc_plugin->gettext('newerversionexists');
                } else if (!$existing && ($status != 'declined' || $this->rc->config->get('kolab_invitation_calendars'))) {
                    if ($status == 'declined' || $event['status'] == 'CANCELLED' || $event_attendee['role'] == 'NON-PARTICIPANT') {
                        $event['free_busy'] = 'free';
                    }

                    // if the RSVP reply only refers to a single instance:
                    // store unmodified master event with current instance as exception
                    if (!empty($instance) && !empty($savemode) && $savemode != 'all') {
                        $master = $this->rc_plugin->lib->mail_get_itip_object($mbox, $uid, $mime_id, 'event');
                        if ($master['recurrence'] && !$master['_instance']) {
                            // compute recurring events until this instance's date
                            if ($recurrence_date = rcube_utils::anytodatetime($instance, $master['start']->getTimezone())) {
                                $recurrence_date->setTime(23, 59, 59);

                                foreach ($this->driver->get_recurring_events($master, $master['start'], $recurrence_date) as $recurring) {
                                    if ($recurring['_instance'] == $instance) {
                                        // copy attendees block with my partstat to exception
                                        $recurring['attendees'] = $event['attendees'];
                                        $master['recurrence']['EXCEPTIONS'][] = $recurring;
                                        $event = $recurring;  // set reference for iTip reply
                                        break;
                                    }
                                }

                                $master['calendar'] = $event['calendar'] = $calendar['id'];
                                $success = $this->driver->new_event($master);
                            } else {
                                $master = null;
                            }
                        } else {
                            $master = null;
                        }
                    }

                    // save to the selected/default calendar
                    if (!$master) {
                        $event['calendar'] = $calendar['id'];
                        $success = $this->driver->new_event($event);
                    }
                } else if ($status == 'declined')
                    $error_msg = null;
            } else if ($status == 'declined' || $dontsave)
                $error_msg = null;
            else
                $error_msg = $this->rc_plugin->gettext('nowritecalendarfound');
        }

        if ($success) {
            $message = $event['_method'] == 'REPLY' ? 'attendeupdateesuccess' : ($deleted ? 'successremoval' : ($existing ? 'updatedsuccessfully' : 'importedsuccessfully'));
            $this->rc->output->command('display_message', $this->rc_plugin->gettext(array('name' => $message, 'vars' => array('calendar' => $calendar['name']))), 'confirmation');
        }

        if ($success || $dontsave) {
            $metadata['calendar'] = $event['calendar'];
            $metadata['nosave'] = $dontsave;
            $metadata['rsvp'] = intval($metadata['rsvp']);
            $metadata['after_action'] = $this->rc->config->get('calendar_itip_after_action', $this->defaults['calendar_itip_after_action']);
            $this->rc->output->command('plugin.itip_message_processed', $metadata);
            $error_msg = null;
        } else if ($error_msg) {
            $this->rc->output->command('display_message', $error_msg, 'error');
        }

        // send iTip reply
        if ($event['_method'] == 'REQUEST' && $organizer && !$noreply && !in_array(strtolower($organizer['email']), $emails) && !$error_msg) {
            $event['comment'] = $comment;
            $this->rc_plugin->itip->set_sender_email($reply_sender);
            if ($this->rc_plugin->itip->send_itip_message($event, 'REPLY', $organizer, 'itipsubject' . $status, 'itipmailbody' . $status))
                $this->rc->output->command('display_message', $this->rc_plugin->gettext(array('name' => 'sentresponseto', 'vars' => array('mailto' => $organizer['name'] ? $organizer['name'] : $organizer['email']))), 'confirmation');
            else
                $this->rc->output->command('display_message', $this->rc_plugin->gettext('itipresponseerror'), 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Find first occurrence of a recurring event excluding start date
     *
     * @param array $event Event data (with 'start' and 'recurrence')
     *
     * @return DateTime Date of the first occurrence
     */
    private function find_first_occurrence($event)
    {
        // fallback to libcalendaring (Horde-based) recurrence implementation
        require_once(__DIR__ . '/../calendar_recurrence.php');
        $recurrence = new calendar_recurrence($this->rc_plugin, $event);

        return $recurrence->first_occurrence();
    }

    /**
     * Releases some resources after successful event save
     */
    private function cleanup_event(&$event)
    {
        // remove temp. attachment files
        if (!empty($_SESSION[calendar::SESSION_KEY]) && ($eventid = $_SESSION[calendar::SESSION_KEY]['id'])) {
            $this->rc->plugins->exec_hook('attachments_cleanup', array('group' => $eventid));
            $this->rc->session->remove(calendar::SESSION_KEY);
        }
    }


    /**
     * Read email message and return contents for a new event based on that message
     */
    public function mail_message2event()
    {
        $this->rc_plugin->ui->init();
        $this->rc_plugin->ui->addJS();
        $this->rc_plugin->ui->init_templates();
        $this->rc_plugin->ui->calendar_list(array(), true); // set env['calendars']

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
                if (!is_array($_SESSION[\calendar::SESSION_KEY]) || $_SESSION[\calendar::SESSION_KEY]['id'] != $eventid) {
                    $_SESSION[\calendar::SESSION_KEY] = array();
                    $_SESSION[\calendar::SESSION_KEY]['id'] = $eventid;
                    $_SESSION[\calendar::SESSION_KEY]['attachments'] = array();
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
                        $_SESSION[\calendar::SESSION_KEY]['attachments'][$id] = $attachment;

                        $attachment['id'] = 'rcmfile' . $attachment['id'];  // add prefix to consider it 'new'
                        $event['attachments'][] = $attachment;
                    }
                }
            }

            $this->rc->output->set_env('event_prop', $event);
        } else {
            $this->rc->output->command('display_message', $this->rc_plugin->gettext('messageopenerror'), 'error');
        }

        $this->rc->output->send('calendar.dialog');
    }
}
