<?php

namespace App\Controllers;

use App\Models\CfopModel;

final class CfopController extends LookupController
{
  protected function modelClass(): string
  {
    return CfopModel::class;
  }
}
