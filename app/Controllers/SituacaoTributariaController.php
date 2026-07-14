<?php

namespace App\Controllers;

use App\Models\SituacaoTributariaModel;

final class SituacaoTributariaController extends LookupController
{
  protected function modelClass(): string
  {
    return SituacaoTributariaModel::class;
  }
}
