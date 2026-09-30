<?php

namespace App\CalDAV\Schedule;

use Sabre\VObject\ITip;

/**
 * Alarms are personal: the organizer's must not reach the attendees, and the attendees' own
 * must survive updates from the organizer (see Broker).
 */
final class Plugin extends \Sabre\CalDAV\Schedule\Plugin
{
    /**
     * An iTIP message is built from the organizer's copy of the event, alarms included. We drop
     * them before anything handles the message (local delivery, iMIP email). The organizer's
     * stored event is untouched: the Broker works on clones.
     */
    public function deliver(ITip\Message $iTipMessage)
    {
        foreach ($iTipMessage->message->getComponents() as $component) {
            $component->remove('VALARM');
        }

        parent::deliver($iTipMessage);
    }

    protected function createITipBroker(): \Sabre\VObject\ITip\Broker
    {
        return new Broker();
    }
}
