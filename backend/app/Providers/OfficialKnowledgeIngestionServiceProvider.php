<?php

namespace App\Providers;

use App\Services\OfficialKnowledgeIngestion\ControlledOfficialKnowledgeManifestProvider;
use App\Services\OfficialKnowledgeIngestion\LocalPypdfTextAcquirer;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeManifestProvider;
use App\Services\OfficialKnowledgeIngestion\OfficialPdfTextAcquirer;
use Illuminate\Support\ServiceProvider;

final class OfficialKnowledgeIngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OfficialPdfTextAcquirer::class, LocalPypdfTextAcquirer::class);
        $this->app->bind(OfficialKnowledgeManifestProvider::class, ControlledOfficialKnowledgeManifestProvider::class);
    }
}
