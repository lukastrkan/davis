<?php

namespace App\CalDAV\Backend;

use Psr\Log\LoggerInterface;
use Sabre\CalDAV\Backend\PDO as SabrePDO;
use Sabre\VObject;
use Sabre\VObject\Recur\NoInstancesException;

/**
 * Sabre's PDO backend throws NoInstancesException (uncaught, 500) when a
 * VEVENT's RRULE combined with EXDATE/UNTIL yields zero valid occurrences
 * (e.g. a client excludes the only occurrence the rule would ever produce).
 * We fall back to treating the object as a single, non-recurring occurrence
 * instead of hard-failing the PUT.
 */
class PDO extends SabrePDO
{
    public function __construct($pdo, private readonly ?LoggerInterface $logger = null)
    {
        parent::__construct($pdo);
    }

    /**
     * Sabre stores whatever a client sends as calendar-timezone (MKCALENDAR / PROPPATCH),
     * but parses it as a VCALENDAR everywhere it reads it back (free-busy, calendar-query,
     * multiget), so a bare TZID or an empty string breaks those requests with a ParseException.
     * Such a value is dropped here: the calendar then behaves as if it had no timezone (UTC).
     */
    public function getCalendarsForUser($principalUri)
    {
        $tzProp = '{'.\Sabre\CalDAV\Plugin::NS_CALDAV.'}calendar-timezone';
        $calendars = parent::getCalendarsForUser($principalUri);

        foreach ($calendars as &$calendar) {
            if (isset($calendar[$tzProp]) && !str_starts_with(ltrim($calendar[$tzProp]), 'BEGIN:VCALENDAR')) {
                unset($calendar[$tzProp]);
            }
        }

        return $calendars;
    }

    protected function getDenormalizedData($calendarData)
    {
        $vObject = VObject\Reader::read($calendarData);
        $componentType = null;
        $component = null;
        $firstOccurence = null;
        $lastOccurence = null;
        $uid = null;
        foreach ($vObject->getComponents() as $component) {
            if ('VTIMEZONE' !== $component->name) {
                $componentType = $component->name;
                $uid = (string) $component->UID;
                break;
            }
        }
        if (!$componentType) {
            throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
        }
        if ('VEVENT' === $componentType) {
            $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
            // Finding the last occurence is a bit harder
            if (!isset($component->RRULE)) {
                if (isset($component->DTEND)) {
                    $lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
                } elseif (isset($component->DURATION)) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate = $endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
                    $lastOccurence = $endDate->getTimeStamp();
                } elseif (!$component->DTSTART->hasTime()) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate = $endDate->modify('+1 day');
                    $lastOccurence = $endDate->getTimeStamp();
                } else {
                    $lastOccurence = $firstOccurence;
                }
            } else {
                try {
                    $it = new VObject\Recur\EventIterator($vObject, (string) $component->UID);
                    $maxDate = new \DateTime(self::MAX_DATE);
                    if ($it->isInfinite()) {
                        $lastOccurence = $maxDate->getTimeStamp();
                    } else {
                        $end = $it->getDtEnd();
                        while ($it->valid() && $end < $maxDate) {
                            $end = $it->getDtEnd();
                            $it->next();
                        }
                        $lastOccurence = $end->getTimeStamp();
                    }
                } catch (NoInstancesException $e) {
                    $this->logger?->warning('Calendar object with UID '.$uid.' has an RRULE that produces no instances, falling back to a single occurrence: '.$e->getMessage());
                    $lastOccurence = $firstOccurence;
                }
            }

            // Ensure Occurence values are positive
            if ($firstOccurence < 0) {
                $firstOccurence = 0;
            }
            if ($lastOccurence < 0) {
                $lastOccurence = 0;
            }
        }

        // Destroy circular references to PHP will GC the object.
        $vObject->destroy();

        return [
            'etag' => md5($calendarData),
            'size' => strlen($calendarData),
            'componentType' => $componentType,
            'firstOccurence' => $firstOccurence,
            'lastOccurence' => $lastOccurence,
            'uid' => $uid,
        ];
    }
}
