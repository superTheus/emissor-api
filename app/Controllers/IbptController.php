<?php

namespace App\Controllers;

use App\Models\IbptModel;

final class IbptController extends LookupController
{
  protected function modelClass(): string
  {
    return IbptModel::class;
  }
}
