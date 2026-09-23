<?php

namespace Tests\Unit;

use App\Services\OfficialKnowledgeIngestion\ControlledOfficialKnowledgeManifestProvider;
use PHPUnit\Framework\TestCase;

class ControlledOfficialKnowledgeManifestProviderTest extends TestCase
{
    public function test_bounded_manifest_has_exactly_fifty_unique_semantic_identities(): void
    {
        $provider = new ControlledOfficialKnowledgeManifestProvider;
        $manifest = $provider->find(ControlledOfficialKnowledgeManifestProvider::KONICA_C1070_V15_BOUNDED_FIFTY);

        $this->assertNotNull($manifest);
        $this->assertCount(50, $manifest->selectors);
        $identities = array_map(
            fn ($selector): string => implode('|', [$selector->sectionNumber, $selector->code, $selector->variantKey]),
            $manifest->selectors,
        );
        $this->assertCount(50, array_unique($identities));
        $this->assertContains('2.24.2|C-8001|DF', $identities);
        $this->assertContains('2.8.13|C-1103|FS-531_FS-612', $identities);
        $this->assertContains('2.8.14|C-1103|FS-532', $identities);
        $this->assertContains('2.25.26|C-C152|MAIN_BODY', $identities);

        $crossPage = collect($manifest->selectors)
            ->first(fn ($selector): bool => $selector->sectionNumber === '2.5.2');
        $this->assertNotNull($crossPage);
        $this->assertSame([1412, 1413], $crossPage->physicalPages);
    }

    public function test_pass_only_manifest_excludes_only_the_known_empty_solution_record(): void
    {
        $provider = new ControlledOfficialKnowledgeManifestProvider;
        $full = $provider->find(ControlledOfficialKnowledgeManifestProvider::KONICA_C1070_V15_BOUNDED_FIFTY);
        $pass = $provider->find(ControlledOfficialKnowledgeManifestProvider::KONICA_C1070_V15_BOUNDED_PASS);

        $this->assertNotNull($full);
        $this->assertNotNull($pass);
        $this->assertCount(49, $pass->selectors);
        $this->assertSame(
            ['2.26.21|C-D0F8|MAIN_BODY'],
            array_values(array_diff($this->identities($full->selectors), $this->identities($pass->selectors))),
        );
    }

    /** @param list<object> $selectors */
    private function identities(array $selectors): array
    {
        return array_map(
            fn ($selector): string => implode('|', [$selector->sectionNumber, $selector->code, $selector->variantKey]),
            $selectors,
        );
    }
}
