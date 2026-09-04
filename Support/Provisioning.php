<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

/**
 * i-MSCP SGW_GraphQL plugin
 * Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * Whether the i-MSCP backend has caught up with the panel's intent.
 *
 * The panel never provisions anything: a write sets a status column to a verb
 * and pokes the daemon, which does the work and then sets the status to 'ok'
 * or to the text of whatever went wrong. So a mutation returns an intent, and
 * this type is how the API says so.
 */
final class Provisioning
{
    const STATE_OK       = 'OK';
    const STATE_PENDING  = 'PENDING';
    const STATE_DISABLED = 'DISABLED';
    const STATE_ORDERED  = 'ORDERED';
    const STATE_ERROR    = 'ERROR';

    /**
     * Statuses the backend consumes as a work queue. An item in one of these
     * is mid-flight and must not be written again.
     */
    const PENDING_STATUSES = array(
        'toadd', 'tochange', 'todelete', 'toenable', 'todisable',
        'torestore', 'tochangepwd', 'topurge'
    );

    /** @var string */
    private $state;

    /** @var string */
    private $raw;

    /** @var string|null */
    private $message;

    private function __construct(string $state, string $raw, ?string $message)
    {
        $this->state = $state;
        $this->raw = $raw;
        $this->message = $message;
    }

    public static function fromStatus(?string $status): self
    {
        $raw = $status === null ? '' : $status;

        if ($status === null || $status === 'disabled') {
            return new self(self::STATE_DISABLED, $raw, null);
        }

        if ($status === 'ok') {
            return new self(self::STATE_OK, $raw, null);
        }

        if ($status === 'ordered') {
            return new self(self::STATE_ORDERED, $raw, null);
        }

        if (in_array($status, self::PENDING_STATUSES, true)) {
            return new self(self::STATE_PENDING, $raw, null);
        }

        // Anything else is the backend's failure text, stored where the status
        // used to be.
        return new self(self::STATE_ERROR, $raw, $status);
    }

    public function getState(): string
    {
        return $this->state;
    }

    /**
     * The literal i-MSCP status string, for support and forward compatibility.
     */
    public function getRaw(): string
    {
        return $this->raw;
    }

    /**
     * Is the backend done with this item?
     *
     * A failed item is settled: the backend has stopped working on it, and a
     * delete is the way out.
     */
    public function isSettled(): bool
    {
        return $this->state !== self::STATE_PENDING;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
