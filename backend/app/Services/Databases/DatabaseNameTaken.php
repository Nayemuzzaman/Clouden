<?php

namespace App\Services\Databases;

/** A role or database with the requested name already exists on the PostgreSQL server. */
class DatabaseNameTaken extends DatabaseException {}
