This PHP file uses read access to a Google calendar, loads events from the calendar and displays them in HTML with semantic markup (for search engines) for each event.  The kind of thing you might want to use this for include:
- Display events that are coming up in the next week in short format (with date, time and event name)
- Display events that are coming up in the next week in full format (with date, time, event name, description and location)
- Display non-recurring events that are coming up in the next 90 days (in short or long format as above)

At time of upload, examples of use of this can be found at https://www.stpaulstephenglos.org.uk:
- Events in the next week in short format on the home page
- Events in the next week in full format at https://www.stpaulstephenglos.org.uk/whats-on/this-week/
- Non recurring events in the next 90 days at https://www.stpaulstephenglos.org.uk/whats-on/upcoming-events/

It relies on Google APIv3 libraries, which can be found here: https://github.com/googleapis/google-api-php-client, but has only been tested with version 2.2.2 of the libraries

Usage of this is covered by the MIT license
