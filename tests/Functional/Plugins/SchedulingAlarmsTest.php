<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plugins;

use App\CalDAV\Schedule\Broker;
use App\CalDAV\Schedule\Plugin;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Server;
use Sabre\VObject\Reader;

/**
 * Alarms are personal: the organizer's never reach the attendee, and the attendee's own
 * survive updates from the organizer.
 */
class SchedulingAlarmsTest extends TestCase
{
    private static function event(string $summary, string $trigger): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davis//Test//EN\r\nBEGIN:VEVENT\r\nUID:alarm-1\r\nDTSTAMP:20260101T100000Z\r\nDTSTART:20261201T100000Z\r\nDTEND:20261201T110000Z\r\nSUMMARY:$summary\r\nORGANIZER;CN=Organiser:mailto:organiser@example.org\r\nATTENDEE;CN=Attendee;PARTSTAT=NEEDS-ACTION:mailto:attendee@example.org\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nTRIGGER:$trigger\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testOrganizerAlarmsAreDroppedAndAttendeeAlarmsKept(): void
    {
        $plugin = new Plugin();
        (new Server())->addPlugin($plugin);

        // The organizer updates an event that carries their own 15 minutes alarm
        $organizerEvent = Reader::read(self::event('Updated meeting', '-PT15M'));
        [$message] = (new Broker())->parseEvent($organizerEvent, 'mailto:organiser@example.org');
        $plugin->deliver($message);

        $this->assertNull($message->message->VEVENT->VALARM);
        $this->assertSame('-PT15M', (string) $organizerEvent->VEVENT->VALARM->TRIGGER);

        // The attendee already had the event, with their own 1 hour alarm
        $attendeeCopy = Reader::read(self::event('Test meeting', '-PT1H'));
        $updated = (new Broker())->processMessage($message, $attendeeCopy);

        $this->assertSame('Updated meeting', (string) $updated->VEVENT->SUMMARY);
        $this->assertCount(1, $updated->VEVENT->select('VALARM'));
        $this->assertSame('-PT1H', (string) $updated->VEVENT->VALARM->TRIGGER);
    }
}
