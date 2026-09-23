<?php

namespace App\Services\OfficialKnowledgeParsing;

enum ParserDiagnosticSeverity: string
{
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}
