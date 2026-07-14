<?php

namespace App\Controllers;

use App\Models\UnidadesModel;

final class UnidadesController extends LookupController
{
  protected function modelClass(): string
  {
    return UnidadesModel::class;
  }
}
