<?php

namespace Calendar\Controllers;

use Calendar\Controllers\Controller;
use rcube_utils;
use rcube_message;
use html;
use rcube;

class MailController extends Controller
{
    const TASK = 'mail';
    /**
     * @var array - roundcube actions to be created
     * @example  ' ["test"] - create "test" action and attach "$this->test()" method to it
     * @example  ' ["test" => "test_method"] - create "test" action and attach "$this->test_method()" method to it
     */
    const ACTIONS = [
        'check_recent',
    ];

    /**
     * @var array - predefined roundcube hooks to be attached 
     * Look ACTIONS
     */
    const HOOKS = [
        'template_object_messagebody' => 'mail_messagebody_html',
        'messages_list' => 'mail_messages_list',
        'message_compose' => 'mail_message_compose'
    ];

    /**
     * Handler for check-recent requests which are accidentally sent to calendar
     */
    function check_recent()
    {
        // NOP
        $this->rc->output->send();
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
     * Add UI element to copy event invitations or updates to the calendar
     */
    public function mail_messagebody_html($p)
    {
        $html = '';
        $has_events = false;
        $ical_objects = $this->rc_plugin->lib->get_mail_ical_objects();

        // show a box for every event in the file
        foreach ($ical_objects as $idx => $event) {
            if ($event['_type'] != 'event')  // skip non-event objects (#2928)
                continue;

            $has_events = true;

            // get prepared inline UI for this event object
            if ($ical_objects->method) {
                $append   = '';
                $date_str = $this->rc->format_date($event['start'], $this->rc->config->get('date_format'), empty($event['start']->_dateonly));
                $date     = new \DateTime($event['start']->format('Y-m-d') . ' 12:00:00', new \DateTimeZone('UTC'));

                // prepare a small agenda preview to be filled with actual event data on async request
                if ($ical_objects->method == 'REQUEST') {
                    $append = html::div(
                        'calendar-agenda-preview',
                        html::tag('h3', 'preview-title', $this->rc_plugin->gettext('agenda') . ' ' . html::span('date', $date_str))
                            . '%before%' . $this->mail_agenda_event_row($event, 'current') . '%after%'
                    );
                }

                $html .= html::div(
                    'calendar-invitebox invitebox boxinformation',
                    $this->rc_plugin->itip->mail_itip_inline_ui(
                        $event,
                        $ical_objects->method,
                        $ical_objects->mime_id . ':' . $idx,
                        'calendar',
                        rcube_utils::anytodatetime($ical_objects->message_date),
                        $this->rc->url(array('task' => 'calendar')) . '&view=agendaDay&date=' . $date->format('U')
                    ) . $append
                );
            }

            // limit listing
            if ($idx >= 3)
                break;
        }

        // prepend event boxes to message body
        if ($html) {
            $this->rc_plugin->ui->init();
            $p['content'] = $html . $p['content'];
            $this->rc->output->add_label('calendar.savingdata', 'calendar.deleteventconfirm', 'calendar.declinedeleteconfirm');
        }

        // add "Save to calendar" button into attachment menu
        if ($has_events) {
            $this->rc_plugin->add_button(array(
                'id'         => 'attachmentsavecal',
                'name'       => 'attachmentsavecal',
                'type'       => 'link',
                'wrapper'    => 'li',
                'command'    => 'attachment-save-calendar',
                'class'      => 'icon calendarlink disabled',
                'classact'   => 'icon calendarlink active',
                'innerclass' => 'icon calendar',
                'label'      => 'calendar.savetocalendar',
            ), 'attachmentmenu');
        }

        return $p;
    }

    public function mail_messages_list($p)
    {
        if (in_array('attachment', (array)$p['cols']) && !empty($p['messages'])) {
            foreach ($p['messages'] as $header) {
                $part = new \StdClass;
                $part->mimetype = $header->ctype;
                if (\libcalendaring::part_is_vcalendar($part)) {
                    $header->list_flags['attachmentClass'] = 'ical';
                } else if (in_array($header->ctype, array('multipart/alternative', 'multipart/mixed'))) {
                    // TODO: fetch bodystructure and search for ical parts. Maybe too expensive?
                    if (!empty($header->structure) && is_array($header->structure->parts)) {
                        foreach ($header->structure->parts as $part) {
                            if (\libcalendaring::part_is_vcalendar($part) && !empty($part->ctype_parameters['method'])) {
                                $header->list_flags['attachmentClass'] = 'ical';
                                break;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Handler for the 'message_compose' plugin hook. This will check for
     * a compose parameter 'calendar_event' and create an attachment with the
     * referenced event in iCal format
     */
    public function mail_message_compose($args)
    {
        // set the submitted event ID as attachment
        if (!empty($args['param']['calendar_event'])) {
            list($cal, $id) = explode(':', $args['param']['calendar_event'], 2);
            if ($event = $this->driver->get_event(array('id' => $id, 'calendar' => $cal))) {
                $filename = asciiwords($event['title']);
                if (empty($filename))
                    $filename = 'event';

                // save ics to a temp file and register as attachment
                $tmp_path = tempnam($this->rc->config->get('temp_dir'), 'rcmAttmntCal');
                file_put_contents($tmp_path, $this->rc_plugin->ical->export(array($event), '', false, array($this->driver, 'get_attachment_body')));

                $args['attachments'][] = array(
                    'path'     => $tmp_path,
                    'name'     => $filename . '.ics',
                    'mimetype' => 'text/calendar',
                    'size'     => filesize($tmp_path),
                );
                $args['param']['subject'] = $event['title'];
            }
        }

        return $args;
    }
}
