<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Exceptions;

use RuntimeException;

/**
 * An rclone call exited non-zero or timed out. The message is already
 * scrubbed of the remote's secrets.
 */
final class RcloneFailed extends RuntimeException {}
