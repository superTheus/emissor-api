<?php

namespace App\Controllers;

use App\Models\NcmModel;

final class NcmController extends LookupController
{
  protected function modelClass(): string
  {
    return NcmModel::class;
  }
}
