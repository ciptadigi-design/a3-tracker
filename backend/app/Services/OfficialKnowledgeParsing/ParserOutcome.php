<?php

namespace App\Services\OfficialKnowledgeParsing;

enum ParserOutcome: string
{
    case PASS = 'PASS';
    case WARN = 'WARN';
    case FAIL = 'FAIL';
}
