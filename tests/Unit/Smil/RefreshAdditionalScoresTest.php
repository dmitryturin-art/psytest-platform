<?php

declare(strict_types=1);

namespace PsyTest\Tests\Unit\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\SmilModule;

/**
 * Пересчёт только дополнительных шкал по сохранённым ответам (05.S4a).
 *
 * Кейсы, пройденные до расширения реестра (35 шкал), должны получить все шкалы
 * своего пола (107 из 109: две шкалы 05.S3.6 — только для другого пола),
 * а проверенное scoring core — базовые шкалы, достоверность, профиль, индексы
 * и интерпретация — остаться байт-в-байт прежним.
 */
final class RefreshAdditionalScoresTest extends TestCase
{
    private const CORE_KEYS = [
        'raw_scores', 't_scores', 'corrected_scores', 'validity', 'profile',
        'indices', 'gender', 'answered_count', 'total_questions', 'completion_rate',
        'interpretation',
    ];

    private SmilModule $module;
    /** @var array<int|string, mixed> */
    private array $answers;

    protected function setUp(): void
    {
        $this->module = new SmilModule();
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/smil-reference-answers-valid.json');
        $this->answers = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testStaleResultGetsTheFullRegistryAndKeepsTheScoringCoreByteForByte(): void
    {
        $fresh = $this->module->calculateResults($this->answers);
        $stale = $this->staleCopy($fresh);
        self::assertCount(35, $stale['additional_scores']);

        $refreshed = $this->module->refreshAdditionalScores($stale, $this->answers);

        self::assertCount(107, $refreshed['additional_scores']);
        self::assertSame($fresh['additional_scores'], $refreshed['additional_scores']);
        foreach (self::CORE_KEYS as $key) {
            self::assertSame(
                json_encode($stale[$key], JSON_THROW_ON_ERROR),
                json_encode($refreshed[$key], JSON_THROW_ON_ERROR),
                "Блок {$key} не должен меняться",
            );
        }
        self::assertSame(array_keys($stale), array_keys($refreshed));
        foreach (['L', 'F', 'K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'] as $scale) {
            self::assertSame($stale['t_scores'][$scale], $refreshed['t_scores'][$scale]);
        }
    }

    public function testRefreshIsIdempotent(): void
    {
        $refreshed = $this->module->refreshAdditionalScores(
            $this->staleCopy($this->module->calculateResults($this->answers)),
            $this->answers,
        );

        self::assertSame($refreshed, $this->module->refreshAdditionalScores($refreshed, $this->answers));
    }

    public function testCurrentResultIsReturnedUnchanged(): void
    {
        $fresh = $this->module->calculateResults($this->answers);

        self::assertSame($fresh, $this->module->refreshAdditionalScores($fresh, $this->answers));
    }

    /**
     * 05.S3.6: результат на 105 шкалах до партии одного пола обновляется ровно один раз.
     *
     * После обновления набор кодов совпадает с реестром для пола сессии, поэтому
     * повторное чтение ничего не меняет — для обоих полов. Если бы сравнение шло
     * с полным реестром (109), каждая сессия считалась бы устаревшей вечно.
     */
    public function testResultBeforeTheSexSpecificBatchIsRefreshedOnceForBothSexes(): void
    {
        foreach (['male' => ['RGM', 'SBM'], 'female' => ['RGF', 'SBF']] as $gender => $own) {
            $answers = $this->answers;
            $answers['gender'] = $gender;
            $fresh = $this->module->calculateResults($answers);
            self::assertSame($gender, $fresh['gender']);
            self::assertCount(107, $fresh['additional_scores'], "{$gender}: шкалы своего пола");

            $before = $fresh;
            foreach (['RGF', 'RGM', 'SBF', 'SBM'] as $code) {
                unset($before['additional_scores'][$code]);
            }
            self::assertCount(105, $before['additional_scores']);

            $refreshed = $this->module->refreshAdditionalScores($before, $answers);
            self::assertSame($fresh['additional_scores'], $refreshed['additional_scores'], "{$gender}: обновлён");
            foreach ($own as $code) {
                self::assertArrayHasKey($code, $refreshed['additional_scores']);
            }
            self::assertSame($refreshed, $this->module->refreshAdditionalScores($refreshed, $answers), "{$gender}: идемпотентно");
            self::assertSame($fresh, $this->module->refreshAdditionalScores($fresh, $answers), "{$gender}: свежий не трогается");
        }
    }

    public function testWithoutItemAnswersNothingChanges(): void
    {
        $stale = $this->staleCopy($this->module->calculateResults($this->answers));

        self::assertSame($stale, $this->module->refreshAdditionalScores($stale, []));
        self::assertSame($stale, $this->module->refreshAdditionalScores($stale, ['gender' => 'female']));
    }

    public function testMissingBlockIsCalculated(): void
    {
        $fresh = $this->module->calculateResults($this->answers);
        $withoutBlock = $fresh;
        unset($withoutBlock['additional_scores']);

        $refreshed = $this->module->refreshAdditionalScores($withoutBlock, $this->answers);

        self::assertSame($fresh['additional_scores'], $refreshed['additional_scores']);
    }

    public function testNormsFollowTheStoredGenderEvenIfAnswersLackIt(): void
    {
        $fresh = $this->module->calculateResults($this->answers);
        self::assertSame('female', $fresh['gender']);
        $answers = $this->answers;
        unset($answers['gender']);

        $refreshed = $this->module->refreshAdditionalScores($this->staleCopy($fresh), $answers);

        self::assertSame($fresh['additional_scores'], $refreshed['additional_scores']);
    }

    /**
     * Результат, посчитанный на прежнем реестре: первые 35 шкал.
     *
     * @param array<string, mixed> $results
     *
     * @return array<string, mixed>
     */
    private function staleCopy(array $results): array
    {
        $results['additional_scores'] = array_slice($results['additional_scores'], 0, 35, true);

        return $results;
    }
}
