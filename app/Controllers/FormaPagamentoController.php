<?php

namespace App\Controllers;

use App\Models\FormaPagamentoModel;

final class FormaPagamentoController extends LookupController
{
  protected function modelClass(): string
  {
    return FormaPagamentoModel::class;
  }
}
