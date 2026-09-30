<?php

namespace App\CalDAV\Schedule;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip\Message;

/**
 * On an update from the organizer, sabre replaces every component of the attendee's copy
 * with the ones from the message, wiping the alarms the attendee set for themselves.
 * We carry them over to the matching component (same type and RECURRENCE-ID).
 */
final class Broker extends \Sabre\VObject\ITip\Broker
{
    protected function processMessageRequest(Message $itipMessage, ?VCalendar $existingObject = null)
    {
        $alarms = [];
        foreach ($existingObject?->getComponents() ?? [] as $component) {
            foreach ($component->select('VALARM') as $alarm) {
                $alarms[$component->name.(string) $component->{'RECURRENCE-ID'}][] = clone $alarm;
            }
        }

        $newObject = parent::processMessageRequest($itipMessage, $existingObject);

        foreach ($newObject->getComponents() as $component) {
            foreach ($alarms[$component->name.(string) $component->{'RECURRENCE-ID'}] ?? [] as $alarm) {
                $component->add($alarm);
            }
        }

        return $newObject;
    }
}
