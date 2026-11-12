<?php

namespace App\Repositories;

/**
 * CRUD stays identical; tenant models resolve qualified tables in the context.
 */
class SeparateSchemaRecordRepository extends SharedTableRecordRepository {}
