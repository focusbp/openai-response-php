<?php

namespace focusbp\OpenAIResponsePhp;

/**
 * StatusManager
 *
 * Interface for managing status information.
 *
 * An implementation is responsible for storing and retrieving
 * a status string that represents the current state of the process,
 * such as progress, last action taken, or an error message.
 *
 * Example implementations may store this in session, memory, DB, etc.
 */
interface StatusManager {

    /**
     * Set (or update) the current status string.
     *
     * Implementations should persist this value so that it can be
     * retrieved later via get_status().
     *
     * @param string $status Human-readable status text
     * @return void
     */
    public function set_status(string $status): void;

    /**
     * Retrieve the current status string.
     *
     * Should return null if no status has been recorded yet.
     *
     * @return string|null Current status text, or null if unavailable
     */
    public function get_status(): ?string;
}
