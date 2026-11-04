<?php

namespace App\Mail\Transport;

use Symfony\Component\Mailer\Exception\TransportException;

/** Only contains our own sanitized delivery diagnostics, never provider responses. */
final class GmailApiTransportException extends TransportException {}
