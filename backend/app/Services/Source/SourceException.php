<?php

namespace App\Services\Source;

use RuntimeException;

/** A user-presentable error while talking to GitHub or a git remote. */
class SourceException extends RuntimeException {}
