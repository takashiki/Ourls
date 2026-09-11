<?php

namespace app\components\OrcaRouter;

/**
 * Raised for any user-actionable credential or catalog failure.
 *
 * The message is safe to show to the user: it is passed through
 * SecretStore::redact() by the route layer before it reaches the browser.
 */
class OrcaRouterException extends \Exception
{
    private $status;

    public function __construct($message, $status = 0, $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->status = (int) $status;
    }

    public function status()
    {
        return $this->status;
    }
}
