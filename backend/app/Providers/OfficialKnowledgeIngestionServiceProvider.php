<?php

namespace App\Providers;

use App\Services\OfficialKnowledgeIngestion\ControlledOfficialKnowledgeManifestProvider;
use App\Services\OfficialKnowledgeIngestion\Jam\LocalPypdfSegmentAcquirer;
use App\Services\OfficialKnowledgeIngestion\Jam\OfficialPdfSegmentAcquirer;
use App\Services\OfficialKnowledgeIngestion\LocalPypdfTextAcquirer;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeManifestProvider;
use App\Services\OfficialKnowledgeIngestion\OfficialPdfTextAcquirer;
use Illuminate\Support\ServiceProvider;

final class OfficialKnowledgeIngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OfficialPdfTextAcquirer::class, LocalPypdfTextAcquirer::class);
        $this->app->bind(OfficialPdfSegmentAcquirer::class, LocalPypdfSegmentAcquirer::class);
        $this->app->bind(OfficialKnowledgeManifestProvider::class, ControlledOfficialKnowledgeManifestProvider::class);
    }
}
