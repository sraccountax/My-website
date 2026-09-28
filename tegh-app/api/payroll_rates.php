<?php
declare(strict_types=1);

/**
 * Tegh 2026 Canadian payroll constants.
 *
 * These tables support an explainable draft estimate. Income tax must still be
 * verified with CRA PDOC (or Revenu Quebec WebRAS for Quebec, which this release
 * deliberately blocks) before a payroll run can be finalized.
 *
 * Primary source: CRA T4127, 122nd and 123rd editions.
 */

const SR_PAYROLL_CALCULATION_VERSION = 'CRA-T4127-2026.2-verified-tax';

function payroll_frequency_periods(string $frequency): int
{
    return match ($frequency) {
        'weekly' => 52,
        'biweekly' => 26,
        'semimonthly' => 24,
        'monthly' => 12,
        default => throw new InvalidArgumentException('Unsupported payroll frequency.'),
    };
}

/** @return array{thresholds:array<int,float>,rates:array<int,float>,constants:array<int,float>,basic:float|string} */
function payroll_federal_schedule(): array
{
    return [
        'thresholds' => [0.0, 58_523.0, 117_045.0, 181_440.0, 258_482.0],
        'rates' => [0.14, 0.205, 0.26, 0.29, 0.33],
        'constants' => [0.0, 3_804.0, 10_241.0, 15_685.0, 26_024.0],
        'basic' => 'dynamic_federal',
    ];
}

/**
 * @return array<string,array{thresholds:array<int,float>,rates:array<int,float>,constants:array<int,float>,basic:float|string}>
 */
function payroll_provincial_schedules(string $half): array
{
    $bc = $half === 'H2'
        ? ['thresholds' => [0.0, 50_363.0, 100_728.0, 115_648.0, 140_430.0, 190_405.0, 265_545.0], 'rates' => [0.0614, 0.077, 0.105, 0.1229, 0.147, 0.168, 0.205], 'constants' => [0.0, 786.0, 3_606.0, 5_676.0, 9_061.0, 13_059.0, 22_884.0], 'basic' => 13_216.0]
        : ['thresholds' => [0.0, 50_363.0, 100_728.0, 115_648.0, 140_430.0, 190_405.0, 265_545.0], 'rates' => [0.0506, 0.077, 0.105, 0.1229, 0.147, 0.168, 0.205], 'constants' => [0.0, 1_330.0, 4_150.0, 6_220.0, 9_604.0, 13_603.0, 23_428.0], 'basic' => 13_216.0];
    $nlBasic = $half === 'H2' ? 15_000.0 : 11_188.0;
    $pe = $half === 'H2'
        ? ['thresholds' => [0.0, 33_928.0, 65_820.0, 106_890.0, 142_520.0, 200_000.0], 'rates' => [0.095, 0.1347, 0.166, 0.1762, 0.19, 0.21], 'constants' => [0.0, 1_347.0, 3_407.0, 4_497.0, 6_464.0, 10_464.0], 'basic' => 15_000.0]
        : ['thresholds' => [0.0, 33_928.0, 65_820.0, 106_890.0, 142_520.0], 'rates' => [0.095, 0.1347, 0.166, 0.1762, 0.19], 'constants' => [0.0, 1_347.0, 3_407.0, 4_497.0, 6_464.0], 'basic' => 15_000.0];

    return [
        'AB' => ['thresholds' => [0.0, 61_200.0, 154_259.0, 185_111.0, 246_813.0, 370_220.0], 'rates' => [0.08, 0.10, 0.12, 0.13, 0.14, 0.15], 'constants' => [0.0, 1_224.0, 4_309.0, 6_160.0, 8_628.0, 12_331.0], 'basic' => 22_769.0],
        'BC' => $bc,
        'MB' => ['thresholds' => [0.0, 47_000.0, 100_000.0], 'rates' => [0.108, 0.1275, 0.174], 'constants' => [0.0, 917.0, 5_567.0], 'basic' => 'dynamic_manitoba'],
        'NB' => ['thresholds' => [0.0, 52_333.0, 104_666.0, 193_861.0], 'rates' => [0.094, 0.14, 0.16, 0.195], 'constants' => [0.0, 2_407.0, 4_501.0, 11_286.0], 'basic' => 13_664.0],
        'NL' => ['thresholds' => [0.0, 44_678.0, 89_354.0, 159_528.0, 223_340.0, 285_319.0, 570_638.0, 1_141_275.0], 'rates' => [0.087, 0.145, 0.158, 0.178, 0.198, 0.208, 0.213, 0.218], 'constants' => [0.0, 2_591.0, 3_753.0, 6_943.0, 11_410.0, 14_263.0, 17_117.0, 22_823.0], 'basic' => $nlBasic],
        'NS' => ['thresholds' => [0.0, 30_995.0, 61_991.0, 97_417.0, 157_124.0], 'rates' => [0.0879, 0.1495, 0.1667, 0.175, 0.21], 'constants' => [0.0, 1_909.0, 2_976.0, 3_784.0, 9_283.0], 'basic' => 11_932.0],
        'NT' => ['thresholds' => [0.0, 53_003.0, 106_009.0, 172_346.0], 'rates' => [0.059, 0.086, 0.122, 0.1405], 'constants' => [0.0, 1_431.0, 5_247.0, 8_436.0], 'basic' => 18_198.0],
        'NU' => ['thresholds' => [0.0, 55_801.0, 111_602.0, 181_439.0], 'rates' => [0.04, 0.07, 0.09, 0.115], 'constants' => [0.0, 1_674.0, 3_906.0, 8_442.0], 'basic' => 19_659.0],
        'ON' => ['thresholds' => [0.0, 53_891.0, 107_785.0, 150_000.0, 220_000.0], 'rates' => [0.0505, 0.0915, 0.1116, 0.1216, 0.1316], 'constants' => [0.0, 2_210.0, 4_376.0, 5_876.0, 8_076.0], 'basic' => 12_989.0],
        'PE' => $pe,
        'SK' => ['thresholds' => [0.0, 54_532.0, 155_805.0], 'rates' => [0.105, 0.125, 0.145], 'constants' => [0.0, 1_091.0, 4_207.0], 'basic' => 20_381.0],
        'YT' => ['thresholds' => [0.0, 58_523.0, 117_045.0, 181_440.0, 500_000.0], 'rates' => [0.064, 0.09, 0.109, 0.128, 0.15], 'constants' => [0.0, 1_522.0, 3_745.0, 7_193.0, 18_193.0], 'basic' => 'dynamic_federal'],
    ];
}

/** @return array<string,mixed> */
function payroll_builtin_rate_release(string $payDate): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
        throw new InvalidArgumentException('Payroll pay date is invalid.');
    }
    $year = (int)substr($payDate, 0, 4);
    if ($year !== 2026) throw new InvalidArgumentException('No bundled payroll release exists for this year.');
    $half = $payDate >= sprintf('%04d-07-01', $year) ? 'H2' : 'H1';
    $rates = [
        'year' => $year,
        'half' => $half,
        'version' => SR_PAYROLL_CALCULATION_VERSION,
        'federal' => payroll_federal_schedule(),
        'provinces' => payroll_provincial_schedules($half),
        'cpp' => [
            'ympe' => 74_600.0,
            'yampe' => 85_000.0,
            'basicExemption' => 3_500.0,
            'rate' => 0.0595,
            'baseRate' => 0.0495,
            'max' => 4_230.45,
            'baseMax' => 3_519.45,
            'cpp2Rate' => 0.04,
            'cpp2Max' => 416.0,
        ],
        'ei' => [
            'mie' => 68_900.0,
            'employeeRate' => 0.0163,
            'employeeMax' => 1_123.07,
            'employerRate' => 0.02282,
            'employerMax' => 1_572.30,
        ],
        'sources' => [
            't4127' => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas.html',
            'pdoc' => 'https://www.canada.ca/en/revenue-agency/services/e-services/digital-services-businesses/payroll-deductions-online-calculator.html',
            'cpp' => 'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/payroll/payroll-deductions-contributions/canada-pension-plan-cpp/cpp-contribution-rates-maximums-exemptions.html',
            'ei' => 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-employers/premium-reduction-program/2026-maximum-insurable-earnings.html',
        ],
    ];
    $rates['id'] = 'cra-t4127-2026-'.($half === 'H2' ? '07' : '01').'-option1-v1';
    $rates['version'] = SR_PAYROLL_CALCULATION_VERSION.'-'.$half;
    $rates['year'] = 2026;
    $rates['effectiveFrom'] = $half === 'H2' ? '2026-07-01' : '2026-01-01';
    $rates['effectiveTo'] = $half === 'H2' ? '2026-12-31' : '2026-06-30';
    $rates['formulaVersion'] = 'tegh-t4127-option1-estimate-v1';
    $rates['label'] = 'CRA T4127 — '.($half === 'H2' ? 'July' : 'January').' 2026';
    $rates['cpp']['firstAdditionalRate'] = 0.01;
    $rates['formulaConstants'] = [
        'federalBasic' => ['maximum'=>16452.0,'minimum'=>14829.0,'reductionStart'=>181440.0,'reductionEnd'=>258482.0],
        'employmentAmount' => 1501.0,
        'manitobaBasic' => 15780.0,
        'ontario' => [
            'surtaxRates'=>[0.20,0.36], 'surtaxThresholds'=>[5818.0,7446.0], 'reductionBase'=>300.0,
            'healthBands'=>[
                ['threshold'=>20000.0,'base'=>0.0,'rate'=>0.06,'maximum'=>300.0],
                ['threshold'=>36000.0,'base'=>300.0,'rate'=>0.06,'maximum'=>450.0],
                ['threshold'=>48000.0,'base'=>450.0,'rate'=>0.25,'maximum'=>600.0],
                ['threshold'=>72000.0,'base'=>600.0,'rate'=>0.25,'maximum'=>750.0],
                ['threshold'=>200000.0,'base'=>750.0,'rate'=>0.25,'maximum'=>900.0],
            ],
        ],
    ];
    // This catalogue versions the current engine inputs. Source matching is not
    // certification of the draft income-tax engine; its verification gate stays on.
    $rates['requiresIncomeTaxVerification'] = true;
    $rates['digest'] = payroll_rate_digest($rates);
    return payroll_validate_rate_release($rates);
}

require_once __DIR__.'/payroll_rate_updates_v5950.php';

/** Central statutory releases apply to every company; tenant marker rows cannot approve a tax year. */
function payroll_rates_for_date(string $payDate, ?string $companyId = null): array
{
    payroll_valid_rate_date($payDate);
    return payroll_select_rate_release(payroll_available_rate_releases(), $payDate);
}
