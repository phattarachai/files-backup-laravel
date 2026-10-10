<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Events;

/**
 * A set's last successful run finished within `stale_after_hours`.
 */
final class FilesBackupHealthy extends FilesBackupStatus {}
