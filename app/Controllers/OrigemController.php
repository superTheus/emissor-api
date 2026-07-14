<?php

namespace App\Controllers;

use App\Models\OrigemModel;

final class OrigemController extends LookupController
{
  protected function modelClass(): string
  {
    return OrigemModel::class;
  }
}
