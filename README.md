# Roundcube Calendar

A calendar module for Roundcube. Fork of [Texas Edition](https://github.com/texxasrulez/caldav_calendar_te), which in turn is a fork of the original [Kolab calendar](https://git.kolab.org/diffusion/RPK/browse/master/plugins/calendar).

This plugin supports CalDAV (Calendaring Extensions to WebDAV).

**Important**: This plugin doesn't work with the Classic skin of Roundcube because no templates are available for that skin. Use Roundcube `skins_allowed` option to limit skins available to the user.

## Requirements

For some general calendar-based operations such as alarms handling or iCal parsing/exporting and UI widgets/style additional library plugins are required and must be installed:

* [indevops/libcalendaring](https://github.com/INDEVOPS/roundcube_libcalendaring)
* [libkolab](https://git.kolab.org/diffusion/RPK/)

For recurring event computation, some utility classes from the Horde project are used. They are packaged in a slightly modified version with this plugin.

## Installation

### Using Composer

1. Add urls to packages repositories to the composer.json file
    ```bash
    {
      "type": "vcs",
      "url": "https://github.com/INDEVOPS/roundcube_calendar"
    },
    {
      "type": "vcs",
      "url": "https://github.com/INDEVOPS/roundcube_libcalendaring"
    }
    ```
1. Add calendar package to the composer.json file
    ```bash
    composer require indevops/calendar
    ```
1. Initialize the calendar database tables
    ```bash
    bin/initdb.sh --dir=plugins/calendar/drivers/caldav/SQL
    ```

### Manual installation

For a manual installation of the calendar plugin (and its dependencies),
execute the following steps. This will set it up with the database backend
driver.

1. Get the source from git
    ```bash
    cd /tmp
    git clone https://github.com/INDEVOPS/roundcube_calendar.git
    git clone https://github.com/INDEVOPS/roundcube_libcalendaring.git
    git clone https://git.kolab.org/diffusion/RPK/roundcubemail-plugins-kolab.git
    cd /<path-to-roundcube>/plugins
    cp -r /tmp/roundcube_calendar/. calendar
    cp -r /tmp/roundcube_libcalendaring/. libcalendaring
    cp -r /tmp/roundcubemail-plugins-kolab/plugins/libkolab . libkolab
    ```
1. Create calendar plugin configuration
    ```bash
    cd calendar/
    cp config.inc.php.dist config.inc.php
    edit config.inc.php
    ```
1. Generate the autoload.php by running
    ```bash
    composer dump-autoload
    ```
1. Include autoload.php in Roundcube autoload file
    ```bash
    cd ../../
    edit vendor/autoload.php
    add require_once __DIR__ . '/../plugins/calendar/vendor/autoload.php';
    ```
1. Initialize the calendar database tables
    ```bash
    bin/initdb.sh --dir=plugins/calendar/drivers/caldav/SQL
    ```
1. Build css styles for the Elastic skin
    ```bash
    lessc --rewrite-urls=all plugins/libkolab/skins/elastic/libkolab.less > plugins/libkolab/skins/elastic/libkolab.min.css
    ```
1. Enable the calendar plugin
    ```bash
    edit config/config.inc.php
    ```
1. Add 'calendar' and dependencies to the list of active plugins:
    ```bash
    $config['plugins'] = array(
      (...)
      'calendar',
      'libcalendaring',
      'libkolab'
    );
    ```

## Changelog

**0.7**
* Add ability to accept attached iTip/ics files
* Fix invitation handling
* Remove hardcoded dependencies
* Remove obsolete packages from `composer.json`
* Break `calendar.php` file into smaller components
* Remove unused drivers for calendars (database, ical, kolab, ldap)
