<?php

namespace App\Controllers;

use App\Models\CestModel;

final class CestController extends LookupController
{
  protected function modelClass(): string
  {
    return CestModel::class;
  }
}
