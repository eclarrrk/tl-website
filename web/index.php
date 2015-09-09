<?php

namespace techlancaster;

use Silex;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../calendar/Event.php';

$app = new Silex\Application();

$app['debug'] = true;

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../views',
));

$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
    $twig->addExtension(new \Twig_Extensions_Extension_Text($app));

    return $twig;
}));

$app->get('/hello/{name}', function ($name) use ($app) {
    return $app['twig']->render('hello.twig', array(
        'name' => $name,
    ));
});

$app->get('/fetchevents', function () use ($app) {
    // Get the API client and construct the service object.
    $client = new Google_Client();
    $client->setApplicationName('Google Calendar API Quickstart');
    $client->setScopes(implode(' ', array(
            Google_Service_Calendar::CALENDAR_READONLY)
    ));
    $client->setAuthConfigFile(__DIR__.'/../conf/client_secret.json');
    $client->setAccessType('offline');
    // Load previously authorized credentials from a file.
    $credentialsPath = __DIR__.'/../conf/calendar-api-quickstart.json';
    if (file_exists($credentialsPath)) {
        $accessToken = file_get_contents($credentialsPath);
    } else {
        //TODO this should be accomplished with an error status
        return $app['twig']->render('calendar.twig', array(
            'message' => 'Invalid Credentials',
        ));
    }
    $client->setAccessToken($accessToken);

    // Refresh the token if it's expired.
    if ($client->isAccessTokenExpired()) {
        $client->refreshToken($client->getRefreshToken());
        file_put_contents($credentialsPath, $client->getAccessToken());
    }
    $service = new Google_Service_Calendar($client);

    $calendarId = '6l7e832ee9bemt1i9c42vltrug@group.calendar.google.com';
    $optParams = array(
        'maxResults' => 29,
        'orderBy' => 'startTime',
        'singleEvents' => TRUE,
        'timeMin' => date('c'),
    );
    $results = $service->events->listEvents($calendarId, $optParams);
    $events = array();

    /**
     * @var $googleEvent Google_Service_Calendar_Event
     */
    foreach ($results->getItems() as $googleEvent) {
        $event = new calendar\Event();
        $event->setId($googleEvent->getId());
        $event->setDescription($googleEvent->getDescription());
        $event->setLocation($googleEvent->getLocation());
        $event->setStart($googleEvent->getStart());
        $event->setEnd($googleEvent->getEnd());
        $event->setSummary($googleEvent->getSummary());
        $events[] = $event;
    }

    // Write the events to a file
    $json = json_encode($events);
    $handler = fopen("events.json", 'w')
        or die("Error opening output file");
    fwrite($handler, $json);
    fclose($handler);

    return $app['twig']->render('calendar.twig', array('message'=>$json));
});

$app->get('/', function () use ($app) {
    $json = json_decode(file_get_contents("events.json"), true);

    // Modify the data to fit into the template
    foreach ($json as $key => $event) {
        /* the title should handle a max of ~50 characters to accomodate 3 lines
            at it's smallest width (browser width of 992px). */
        if(strlen($event['summary']) > 47) {
            $json[$key]['summary'] = substr($event['summary'], 0, 47) . "...";
        }

        /* description contains url. code found at:
           http://krasimirtsonev.com/blog/article/php--find-links-in-a-string-and-replace-them-with-actual-html-link-tags */
        $str = $event['description'];
        $reg_exUrl = "/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/";
        $urls = array();
        $urlsToReplace = array();
        if(preg_match_all($reg_exUrl, $str, $urls)) {
            $numOfMatches = count($urls[0]);
            $numOfUrlsToReplace = 0;
            for($i=0; $i<$numOfMatches; $i++) {
                $alreadyAdded = false;
                $numOfUrlsToReplace = count($urlsToReplace);
                for($j=0; $j<$numOfUrlsToReplace; $j++) {
                    if($urlsToReplace[$j] == $urls[0][$i]) {
                        $alreadyAdded = true;
                    }
                }
                if(!$alreadyAdded) {
                    array_push($urlsToReplace, $urls[0][$i]);
                }
            }
            $numOfUrlsToReplace = count($urlsToReplace);
            for($i=0; $i<$numOfUrlsToReplace; $i++) {
                $str = str_replace($urlsToReplace[$i], "<a href=\"".$urlsToReplace[$i]."\">".$urlsToReplace[$i]."</a> ", $str);
            }
            error_log($str);
            $json[$key]['description'] = $str;
        }

        /* location needs link and truncation
           (for one line, cap at ~30 characters) */
        $location = $event['location'];
        $short_location = substr($location, 0, strpos($location, ','));
        $json[$key]['location'] = '<a href="https://www.google.com/maps/?q=' . $location . '" >' . $short_location . '</a>';
    }

    return $app['twig']->render('index.twig', array(
        'events'=>$json
    ));
});

$app->run();
