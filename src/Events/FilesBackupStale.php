<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Events;

/**
 * A set's last successful run finished more than `stale_after_hours` ago, the
 * target records none, or it could not be read (see $error).
 */
final class FilesBackupStale extends FilesBackupStatus {}
