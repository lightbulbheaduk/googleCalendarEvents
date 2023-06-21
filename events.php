IT License

Copyright (c) [2023] [Lightbulbheaduk]

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

<?php
/*
* Global variables
*/

// Calendar id (get this when calendar is created) - this will be in the format <unique details.calendar.google.com>
$calendar_url = '';

// Path to Google API library (by default I've put it in the template directory of Wordpress install, so set it to "get_template_directory('')"
$google_api_lib_path = get_template_directory('');

// Application name - this is the name of the application 
$application_name = 'My Calendar';

// Timezone - set this to be where the events are happening
$default_timezone = 'Europe/London';

// URL of page listing all events in the next week
$next_week_page = '';

// URL of page listing non recurring events
$non_recurring_events_page = '';

/*
*  Loads and returns in short format, events happening in the next 7 days
*/
function loadThisWeek($address) {
	$eventArray = loadCalendar($address, 7, TRUE);
	$eventsBlob = printEvents($eventArray, 'short', 'week');
	$eventsSchemaBlob = printEventsSchema($eventArray);
	return $eventsBlob . $eventsSchemaBlob;
}

/*
*  Loads and returns in short format, non-recurring events happening in the next 90 days
*/
function loadUpcoming($address) {
	$eventArray = loadCalendar($address, 90, FALSE);
	$eventsBlob = printEvents($eventArray, 'short', 'diary');	
	$eventsSchemaBlob = printEventsSchema($eventArray);
	return $eventsBlob . $eventsSchemaBlob;
}

/*
*  Loads and returns in table format, all events happening in the next 30 days
*/
function loadThisMonthTable($address) {	
	$eventArray = loadCalendar($address, 30, TRUE);
	$eventsTable = printEventsTable($eventArray);
	return $eventsTable;
}

/*
*  Loads and returns in full format, events happening in the next 7 days
*/
function loadThisWeekFull($address) {
	$eventArray = loadCalendar($address, 7, TRUE);
	$eventsBlob = printEvents($eventArray, 'full', 'week');
	$eventsSchemaBlob = printEventsSchema($eventArray);
	return $eventsBlob . $eventsSchemaBlob;	
}

/*
*  Loads and returns in full format, events happening in the next 90 days
*/
function loadUpcomingFull($address) {
	$eventArray = loadCalendar($address, 90, FALSE);
	$eventsBlob = printEvents($eventArray, 'full', 'diary');
	$eventsSchemaBlob = printEventsSchema($eventArray);
	return $eventsBlob . $eventsSchemaBlob;	
}

/*
*  Loads a calendar and returns an array of events
*  @param calendarUrl - the URL of the Google calendar - currently unused, but could be used more if multiple calendars are needed 
*  @param days - the number of days to retrieve
*  @param singleEvent - whether multiple occurences of a recurring event should be treated as a single event
*/
function loadCalendar($calendarUrl, $days, $singleEvent) {

	// over-ride any server time zone - you may or may not wish to change this
	date_default_timezone_set($GLOBALS['default_timezone']);

	// require Google API v3 libraries
	// they're on github at https://github.com/googleapis/google-api-php-client
	// NB: This is untested with versions of the library above 2.2.2
	require_once($GLOBALS['google_api_lib_path'] . "/google-api-php-client-2.2.2/vendor/autoload.php");

	// Calendar id (get this when calendar is created)
	// This will be in the format <unique details.calendar.google.com>
	$calName = $GLOBALS['calendar_url'];

	// Create a new client
	$client = new Google_Client();
	$client->setApplicationName($GLOBALS['application_name']);

    	// https://neal.codes/blog/google-calendar-api-on-g-suite
	putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $GLOBALS['google_api_lib_path'] . "/google-api-php-client-2.2.2/service-account.json");
    	$client->useApplicationDefaultCredentials();
    	$client->setScopes("https://www.googleapis.com/auth/calendar.readonly");

    	$service = new Google_Service_Calendar($client); 

	// Set the query parameters - sadly we have to handle recurrence in the response
	$params = array();

	if ($singleEvent) {
		// the "orderBy" can only be used when recurring events are "flattened" into single events
		// and order direction cannot be specified (ascending is only option)
		$params = array(
 			'timeMin' => (new DateTime())->format(DateTime::RFC3339),
 			'timeMax' => (new DateTime())->add(new DateInterval('P' . $days . 'D'))->format(DateTime::RFC3339),
			'singleEvents' => TRUE,
			'orderBy' => "startTime",
		);
	} else {
		$params = array(
 			'timeMin' => (new DateTime())->format(DateTime::RFC3339),
 			'timeMax' => (new DateTime())->add(new DateInterval('P' . $days . 'D'))->format(DateTime::RFC3339),
		);		
	}

	// do the query
	try {
		$events = $service->events->listEvents($calName, $params);
	} 
	catch (Google_Service_Exception $e) {
		echo "Sorry, unable to load events from calendar - please try later";
		//print_r($e);
		return;
	}
		
	$event_array = array();
	
	$numberOfResults = 0;
		
	// sort out the results to choose the ones we want and put them into an array for displaying later
	foreach ($events->getItems() as $event) {
		$eventID = $event->getID();

		$fullEvent = $service->events->get($calName, $eventID);
		
		$recurrenceException = FALSE;
		$recurrence = $fullEvent->getRecurrence();
		$recurringEventID = $fullEvent->getRecurringEventID();

		// check to see if this event is an exception to a recurring event by checking for an recurring event id
		// if there is an recurring event id, but recurrence is empty, this is an exception to a recurring event		
		if ($recurringEventID!="" && $recurrence=="") {
			$recurrenceException = TRUE;
			
			// uncomment the row below for debugging
			// echo "<!--- event id " . $eventID . " (" . $event->getSummary() . ") is an exception to a recurring event -->\n";
		}

		// add the event to the array only if:
		// - $singleEvent == TRUE - we're looking for all events in this time period and don't care about recurrence
		// OR
		// - $singleEvent == FALSE - we don't want any recurring events
		// - $recurrence=="" - there is no recurrence on this event  
		// - $recurrenceException=='false' - event is not an exception to a recurring event
		if ($singleEvent==TRUE || ($singleEvent==FALSE && $recurrence=="" && $recurrenceException==FALSE)) {
			
			$eventTitle = $fullEvent->getSummary();
			
			$dateStart = "";
    			$dateEnd = "";
    			$startTime = "";
    			$endTime = "";
			$startTimestamp = "";
			$endTimestamp = "";

			// clean up the dates and times
			$startTimestamp = $fullEvent->getStart()->getDateTime();
			$endTimestamp = $fullEvent->getEnd()->getDateTime();

			//split start date from string and convert utc to gmt 12 hour am/pm
			$dateStartArray=explode("T", $startTimestamp);
			if($dateStartArray[1]==""){
				// all day event if there's no time element					
				$startTime="All Day";
				$dateStart=convertDate('D j F Y', strtotime($fullEvent->getStart()->getDate()));
				
				// set the start timestamp to just be the date, so events can be sorted in diary
				$startTimestamp = $fullEvent->getStart()->getDate();
			} else {
				$startTime=convertDate('H:i', strtotime($startTimestamp));
				$dateStart=convertDate('D j F Y', strtotime($dateStartArray[0]));
			}

			//split start date from string and convert utc to gmt 12 hour am/pm
			$dateEndArray=explode("T", $endTimestamp);
			if($dateEndArray[1]==""){
				// all day event if there's no time element					
				$endTime="";
				
				// remove 1 day from the end time (all-day events end at midnight on the next day)
				$dateEnd=convertDate('D j F Y', strtotime('-1 day', strtotime($fullEvent->getEnd()->getDate())));
			
			} else {
				$endTime=convertDate('H:i', strtotime($endTimestamp));
				$dateEnd=convertDate('D j F Y', strtotime($dateEndArray[0]));
			}

			$eventLocation = $fullEvent->getLocation();
			$eventDescription = $fullEvent->getDescription();

			// create an event for the array
			$row_array = array('start_date' => $dateStart, 'start_time' => $startTime, 'end_date' => $dateEnd, 'end_time' => $endTime, 'event_title' => $eventTitle, 'description' => $eventDescription, 'location' => $eventLocation, 'start_timestamp' => $startTimestamp, 'end_timestamp' => $endTimestamp);
		
			// add the event to the array
			$event_array[] = $row_array;
		}
	}

	return $event_array;
}

/*
*  Prints out HTML for the events that have been specified onto the screen - divs can be styled as you wish with CSS
*  @param eventArray - the array of events
*  @param format - whether it's in short or long format
*  @param timePeriod - whether it's events for a week or for a day
*/
function printEvents($eventArray, $format, $timePeriod) {
	$eventsString = "";
	
	if (count($eventArray) == 0) {
		$eventsString = $eventsString . "<p>Sorry, no upcoming events found</p>";
	} else {
		// diary array will be in random order... need to sort it
		if ($timePeriod == "diary") {

			// sort the events by start_timestamp
			usort($eventArray, function($a, $b) {
    				
  				$ad = new DateTime($a['start_timestamp']);
  				$bd = new DateTime($b['start_timestamp']);

  				if ($ad == $bd) {
    					return 0;
 				}

  				return $ad > $bd ? 1 : -1;
			});	
		}
		// keep track of dates of events that have been printed already in this variable so that 
		// each date is only printed once
		$existingDates = " ";

        	$noOfEvents = 0;
		
		$eventsString = $eventsString . "<!--- Found " . count($eventArray) . " events --->";

		// iterate over the array forwards to get the soonest event first
		for ($index=0; $index<=count($eventArray)-1; $index++)
		{
			// extract the row object from the list of results
			$row_object = $eventArray[$index];

			$dateStart = $row_object['start_date'];
			$dateEnd = $row_object['end_date'];
			$startTime = $row_object['start_time'];
			$endTime = $row_object['end_time'];
			$startTimestamp = $row_object['start_timestamp'];
			$endTimestamp = $row_object['end_timestamp'];
			$eventTitle = $row_object['event_title'];
			$descriptionString = $row_object['description'];
			$locationString = $row_object['location'];

            		$noOfEvents += 1;

			$dateExists = strpos($existingDates,$dateStart);

            // if we're doing short format, only keep going if:
            // we're on 5 events or fewer
            // OR
            // we're on more than 5 events, but we haven't shown all events for a day
            if ($format == "full" || ($format == "short" && ($noOfEvents <=5 || ($noOfEvents > 5 && $dateExists)))) { 

			if ($dateStart == $dateEnd) {
				// Add the date as the title (but only if it doesn't exist already)

				if ($dateExists == false) {
					
					// add the date string to the list of existing dates
					$existingDates .= " " . $dateStart;

					// if this is not the first date that has been created, add in a spacer
					//if (count($eventArray)-1!=$index) {
					if ($index!=0) {
						$eventsString = $eventsString . "<hr class='event_spacer' />";
					}

					$eventsString = $eventsString . "<div class='event_date'>";
					$eventsString = $eventsString . $dateStart;	
					$eventsString = $eventsString . "</div>";
				}

				// Add event time slot
				if ($startTime != "") {
					$eventsString = $eventsString . "<span class='event_time'>";
					$eventsString = $eventsString . $startTime;
					if ($endTime != "") {
						$eventsString = $eventsString . "-" . $endTime;
					}
					$eventsString = $eventsString . "</span>";
				}
			} else {
				// Add the date as the title (but only if it doesn't exist already)

				if ($dateExists == false) {

					// if this is not the first date that has been created, add in a spacer
					// if (count($eventArray)-1!=$index) {
					if ($index != 0) {
						$eventsString = $eventsString . "<hr class='event_spacer' />";
					}

					$eventsString = $eventsString . "<div class='event_date'>";
					$dateTitle = $dateStart . " ";
					if ($startTime != "" && $endTime != "") {
						$dateTitle .= "(" . $startTime . ") ";
					}
					$dateTitle .= "- " . $dateEnd;
					if ($endTime != "" && $endTime != "") {
						$dateTitle .= " (" . $endTime . ")";
					}
					$eventsString = $eventsString . $dateTitle;
					$eventsString = $eventsString . "</div>";		
				} 

				// Add event time slot
				if ($startTime != "") {
					$eventsString = $eventsString . "<span class='event_time'>";
					$eventsString = $eventsString . $startTime;
					if ($endTime != "") {
						$eventsString = $eventsString . "-" . $endTime;
					}
					$eventsString = $eventsString . "</span>";
				}		
			}

			$eventsString = $eventsString . "<span class='event_title'>";

			// create a hash - (date + event title, with spaces stripped)
			$eventIdentifier = urlencode(preg_replace('/\s+/', '', $dateStart . $eventTitle));

			if ($format == 'full') {
				// add in a hash for the event, based on date and title
				$eventsString = $eventsString . "<a name='" . $eventIdentifier . "'>" . $eventTitle . "</a>";
			} else {
				
				$WEEK_PAGE = $GLOBALS['next_week_page'];
				$DIARY_PAGE = $GLOBALS['non_recurring_events_page'];

				// choose which page to navigate to from this short format event
				$pageToNavTo = '';
				if ($timePeriod == 'week') {
					$pageToNavTo = $WEEK_PAGE;
				} else {
					$pageToNavTo = $DIARY_PAGE;
				}			

				// make the title a link to the actual thing
				$eventsString = $eventsString . "<a href='" . $pageToNavTo . "#" . $eventIdentifier. "'>" . $eventTitle . "</a>";
			}

			$eventsString = $eventsString . "</span>";

			$eventsString = $eventsString . "<br class='clearboth' />";

			if ($format == 'full') {
				// add description
				if ($descriptionString != "") {
					$eventsString = $eventsString . "<div class='event_description'>";
					$descriptionArray = explode('\n', $descriptionString);

					foreach ($descriptionArray as $descriptionText) {
						// replace with a proper link no longer needed in Wordpress (done automatically)
						// $descriptionText = replaceURLWithHTMLLinks($descriptionText);
                        			$descriptionText = preg_replace("/\\n/", "<br />", $descriptionText);
                        			$eventsString = $eventsString . $descriptionText;
					}
					$eventsString = $eventsString . "</div>";
				}

				// add location
				if ($locationString != "" && $locationString != "zoom.us") {

                    			// turn it into a clickable location
                    			$urlencodedlocation = urlencode($locationString);
                    			$locationlink = "https://maps.google.com/maps?hl=en-GB&q=" . $urlencodedlocation . "&source=calendar";

					$eventsString = $eventsString . "<div class='event_location'>";
					$eventsString = $eventsString . "Location: <a href='" . $locationlink . "'>" . $locationString . "</a>";
					$eventsString = $eventsString . "</div>";
				}

				$eventsString = $eventsString . "<br />";
			}
            }
		}
	}
	
	return $eventsString;
}

/*
*  Prints out the events that have been specified onto the screen, as an HTML table
*  @param eventArray - the array of events
*/
function printEventsTable($eventArray) {
	
	$eventsString = "";
	
	if (count($eventArray) == 0) {
		$eventsString = $eventsString . "<p>There aren't currently any upcoming events scheduled.</p>";
	} else {
		// start the table
		$eventsString = $eventsString . "<table>";
		
		// keep track of current date and times and text (for each row)
		$currentDate = " ";
		$eventTimes = " ";
		$eventText = " ";

		// iterate over the array forwards to get the soonest event first
		for ($index=0; $index<=count($eventArray)-1; $index++)
		{
			// extract the row object from the list of results
			$row_object = $eventArray[$index];

			$dateStart = $row_object['start_date'];
			$startTime = $row_object['start_time'];
			$eventTitle = $row_object['event_title'];

			// check whether we are just building up event details or ready to output
            		if ($dateStart != $currentDate) {
				// new date, so check whether it's the first date
				if ($currentDate == " ") {
					// set the current date
					$currentDate = $dateStart;
				} else {
					// not the first date, so output the eventTimes and eventText cells
					$eventsString = $eventsString . "<td>" . $eventTimes . "</td>\n";
					$eventsString = $eventsString . "<td>" . $eventText . "</td>\n";
					$eventsString = $eventsString . "</tr>\n";
					$currentDate = $dateStart;
				}
				
				$date = DateTime::createFromFormat('D j F Y', $dateStart);
				$date = $date->format('l jS');
				
				// now start the next row and output the date
				$eventsString = $eventsString . "<tr>\n<td>" . $date . "</td>\n";
			
				$eventTimes = $startTime;
				$eventText = $eventTitle;
			} else {
				// build up the times and titles
				$eventTimes = $eventTimes . "<br />" . $startTime;
				$eventText = $eventText . "<br />" . $eventTitle;
			}
		}
		$eventsString = $eventsString . "</table>";
	}
	
	return $eventsString;
}

/*
*  Prints out the events that have been specified into a json schema with semantic markup, so search engines can identify events - this will not be displayed on screen
*  @param eventArray - the array of events
*  @param format - whether it's in short or long format
*  @param timePeriod - whether it's events for a week or for a day
*/
function printEventsSchema($eventArray) {
	
	$eventsString = "";
	
	if (count($eventArray) == 0) {
		// Do nothing
	} else {
		// start the json schema and the array of events
		$eventsString = $eventsString . "<script type='application/ld+json'>\n";
		$eventsString = $eventsString . "{";
  		$eventsString = $eventsString . '"@context": "http://schema.org",';
  		$eventsString = $eventsString . '"@graph": [';

		// iterate over the array forwards to get the soonest event first
		for ($index=0; $index<=count($eventArray)-1; $index++)
		{
			// extract the row object from the list of results
			$row_object = $eventArray[$index];
	
			$startTimestamp = $row_object['start_timestamp'];
			$endTimestamp = $row_object['end_timestamp'];
			$eventTitle = $row_object['event_title'];
			$descriptionString = $row_object['description'];

			if ($descriptionString != "") {
				$descriptionArray = explode('\n', $descriptionString);
				$description = "";

				foreach ($descriptionArray as $descriptionText) {
                    			$descriptionText = preg_replace("/\\n/", "\\\\n", $descriptionText);
					$descriptionText = htmlspecialchars($descriptionText);
					$description = $description . " " . $descriptionText;
				}
			} else {
				$description = "Come and join us - all are welcome";
			}
								
			if ($endTimestamp == "") {
				$endTimestamp = $startTimestamp;
			}
			
			$locationString = $row_object['location'];
			
			if ($locationString == "zoom.us") {
				$eventAttendanceMode = "https://schema.org/OnlineEventAttendanceMode";
				$eventType = "VirtualLocation";
			} else {
				$eventAttendanceMode = "https://schema.org/OfflineEventAttendanceMode";
				$eventType = "Place";
			}
			
			// if we're not at the first element, we need to add an array delimiter
			if ($index!=0) {
				$eventsString = $eventsString . ",";
			}
			
			// print the event
			$eventsString = $eventsString . "{";
			$eventsString = $eventsString . '"@type": "Event",';
			$eventsString = $eventsString . '"name": "' . $eventTitle . '",';
			$eventsString = $eventsString . '"description": "' . $description . '",';
			$eventsString = $eventsString . '"startDate": "' . $startTimestamp . '",';
			$eventsString = $eventsString . '"endDate": "' . $endTimestamp . '",';
			$eventsString = $eventsString . '"eventAttendanceMode": "' . $eventAttendanceMode . '",';
			$eventsString = $eventsString . '"location": {';
			$eventsString = $eventsString . '"@type": "' . $eventType . '",';
			if ($locationString == "zoom.us") {
				$eventsString = $eventsString . '"url":"zoom.us"';
			} else {
    			$eventsString = $eventsString . '"address": "' . $locationString . '"';
			}
			$eventsString = $eventsString . "}";
			$eventsString = $eventsString . "}";

        }   

		$eventsString = $eventsString . "]";
		$eventsString = $eventsString . "}\n";
		$eventsString = $eventsString . "</script>\n";
	}
	
	return $eventsString;
}

/*
*  Find urls in text and replace them with html links, then return the text
*  @param inputText - the text to parse
*/
function replaceURLWithHTMLLinks($inputText) {
    	//URLs starting with http://, https://, or ftp://
	$replacedText = preg_replace("/http\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/", "<a href=\"$0\">$0</a>", $inputText);
	$replacedText = preg_replace("/https\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/", "<a href=\"$0\">$0</a>", $replacedText);
	$replacedText = preg_replace("/ftp\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/", "<a href=\"$0\">$0</a>", $replacedText);

	//URLs starting with www. (without // before it, or it'd re-link the ones done above)
	$replacedText = preg_replace("/(^|[^\/])(www)\.[a-zA-Z]{2,3}(\/\S*)?/", "<a href=\"http://$0\">$0</a>", $replacedText);

    	return $replacedText;
}

/*
*  Convert a date into GMT/BST depending on daylight saving
*  @param layout - the php format of the date to output
*  @param eventDate - the date to play with
*/
function convertDate($layout, $eventDate)
{
	$daylight_saving = date('I', $eventDate);

	//echo "<!--- daylight saving is " . $daylight_saving . "-->";

	if ($daylight_saving) {
		// 3600 seconds is one hour
		$zone=3600;
	} else {
		$zone=0;
	}

	//echo "<!--- added " . $zone . " seconds to date -->";

	$date=gmdate($layout, $eventDate + $zone);
	return $date;
} 

?>
