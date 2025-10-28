<?php

namespace focusbp\OpenAIResponsePhp;

/**
 * Recorder
 *
 * Interface responsible for persisting the conversation message history.
 *
 * Example implementations:
 *  - SessionRecorder: uses $_SESSION
 *  - FileRecorder:    uses a local file
 */
interface Recorder
{
    /**
     * Retrieve the full current message history.
     *
     * The return value must be an array of message objects in the form:
     * [
     *   ['role' => 'user', 'content' => '...'],
     *   ...
     * ]
     *
     * Implementations must return an empty array [] if nothing has been stored yet.
     *
     * @return array
     */
    public function read(): array;

    /**
     * Save/overwrite the entire message history.
     *
     * The argument should have the same shape as the array returned by read().
     *
     * @param array $messages
     * @return void
     */
    public function write(array $messages): void;

    /**
     * Append a single message.
     *
     * $role:
     *   "system" / "user" / "assistant" / "tool" / etc.
     *
     * $content:
     *   Free-form. Can be a string or a structured value,
     *   but should stay consistent with the caller's expectations.
     *
     * @param string $role
     * @param mixed  $content
     * @return void
     */
    public function append(string $role, $content): void;
}

