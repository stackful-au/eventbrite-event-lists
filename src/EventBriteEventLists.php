<?php

namespace Stackful;

use GuzzleHttp\Client;

class EventBriteEventLists {

    const URI = 'https://www.eventbriteapi.com/v3';
    const CACHE_EVENTS = 'events.json';
    const CACHE_VENUES = 'venues.json';
    const CACHE_ORGANIZERS = 'organizers.json';

    protected $credentials;
    protected $force = false;
    protected $filesystem;
    protected $client;
    protected $ttl = 1800;

    public function __construct($config) 
    {
        $this->credentials = $config['credentials'];
        $this->force = $config['force'];
        if (array_key_exists('ttl', $config)) {
            $this->ttl = $config['ttl'];
        }
        $this->client = new Client();
    }

    public function setFileSystem($filesystem) 
    {
        $this->filesystem = $filesystem;
    }

    public function getCache($cache) {
        if (!$this->filesystem) {
            return null;
        }
        if ($this->filesystem->has($cache)) {
            return json_decode($this->filesystem->read($cache));
        }
    }

    public function putCache($cache, $data) {
        if ($this->filesystem) {
            $this->filesystem->put($cache, json_encode($data));
        }
    }

    public function request($method, $path, $config = []) 
    {
        $config['headers']['Authorization'] = "Bearer {$this->credentials['token']}";
        try 
        {
            return $this->client->request($method, self::URI . $path, $config);
        }
        catch (\Exception $e) 
        {
            return $e;
        }
    }

    public function getEvents() 
    {
        $events = array_reduce(
            json_decode(
                $this->request(
                    'GET', 
                    "/events/search?user.id={$this->credentials['user.id']}"
                )->getBody()
            )->events, 
            function($carry, $event) {
                $carry[$event->id] = $event;
                return $carry;
            }
            , []
        );

        $cached = $this->getCache(self::CACHE_EVENTS) ?? (object)[];
        $venues = $this->getCache(self::CACHE_VENUES) ?? (object)[];
        $organizers = $this->getCache(self::CACHE_ORGANIZERS) ?? (object)[];

        foreach ($events as &$event) 
        {
            if (
                !$this->force && 
                property_exists($cached, $event->id) &&
                ((time() - strtotime($event->changed)) > $this->ttl)
            ) 
            {
                $event = $cached->{$event->id};
            }
            else
            {            
                $event->venue = $this->getVenue($event->venue_id);
                $event->organizer = $this->getOrganizer($event->organizer_id);
                $event->tickets = $this->getTickets($event->id);
                $event->start->localWithTimezone = "{$event->start->local} {$event->start->timezone}";
                $event->end->localWithTimezone = "{$event->end->local} {$event->end->timezone}";
            }
            $venues->{$event->venue->id} = $event->venue;
            $organizers->{$event->organizer->id} = $event->organizer;
        }

        $this->putCache(self::CACHE_EVENTS, $events);
        $this->putCache(self::CACHE_VENUES, $venues);
        $this->putCache(self::CACHE_ORGANIZERS, $organizers);

        return $events;
    }

    public function getVenue($venueId) 
    {
        if (!$this->force) 
        {
            $venues = $this->getCache(self::CACHE_VENUES);
            if ($venues && property_exists($venues, $venueId)) 
            {
                return $venues->{$venueId};
            }
        }
        $venue = json_decode($this->request('GET', "/venues/{$venueId}")
            ->getBody());
        return $venue;
    }

    public function getOrganizer($organizerId) 
    {
        if (!$this->force) 
        {
            $organizers = $this->getCache(self::CACHE_ORGANIZERS);
            if ($organizers && property_exists($organizers, $organizerId)) {
                return $organizers->{$organizerId};
            }
        }
        $organizer = json_decode($this->request('GET', "/organizers/{$organizerId}")
            ->getBody());
        return $organizer;
    }

    public function getTickets($eventId) 
    {
        $tickets = json_decode($this->request('GET', "/events/{$eventId}/ticket_classes")
            ->getBody());
        return $tickets;
    }

}