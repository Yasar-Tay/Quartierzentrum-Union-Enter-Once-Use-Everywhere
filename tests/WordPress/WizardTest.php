<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\FieldWizard;
use EnterOnce\WordPress\Options;

/**
 * Plan 4.7: the wizard learns the stand-in's fields by comparison, without the plugin knowing
 * their names. The test plays the administrator: it changes values the way the stand-in's edit
 * screen does (qtp_standin_apply_form) and presses the wizard's buttons.
 */
final class WizardTest extends WpTestCase
{
    private int $draft;

    protected function setUp(): void
    {
        parent::setUp();
        FieldWizard::reset();
    }

    /** The administrator creates «Enter Once Lernentwurf» in the edit screen and saves it as a draft. */
    private function createLearningDraft(): int
    {
        $id = wp_insert_post(['post_type' => 'events', 'post_status' => 'draft', 'post_title' => FieldWizard::LEARN_TITLE]);
        qtp_standin_apply_form((int) $id, []);

        return (int) $id;
    }

    /** The administrator changes values in the edit screen and saves. */
    private function edit(array $changes, array $remove = []): void
    {
        $form = qtp_standin_form_values($this->draft);
        foreach ($remove as $k) {
            unset($form[$k]);
        }
        qtp_standin_apply_form($this->draft, array_replace($form, $changes));
    }

    public function testWizardLearnsTheStandInWithoutKnowingIt(): void
    {
        $this->configure(['field_map' => [], 'field_map_verified' => false]);
        $wizard = new FieldWizard();
        self::assertFalse($wizard->begin(), 'no draft yet');
        $this->draft = $this->createLearningDraft();
        self::assertTrue($wizard->begin(), FieldWizard::state()['message']);
        self::assertSame('noise', FieldWizard::state()['step']);
        $this->edit([]);
        self::assertTrue($wizard->compare(), 'second baseline: save without an edit');
        self::assertSame('date', FieldWizard::state()['step']);

        self::assertFalse($wizard->compare(), 'nothing changed yet');
        $this->edit(['qx_a' => '2031-04-23']);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        $this->edit(['qx_b' => '07:45']);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        $this->edit(['qx_c' => '09:05']);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        $this->edit(['qx_d' => '1']);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        $this->edit(['qx_e' => 'So']);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        // A mistake: two switches at once is refused, and the wizard can start the step again.
        $this->edit(['qx_f' => '1', 'qx_g' => '1']);
        self::assertFalse($wizard->compare());
        self::assertStringContainsString('mehr als ein Feld', FieldWizard::state()['message']);
        $this->edit([], ['qx_f', 'qx_g']);
        self::assertTrue($wizard->resnapshot());
        $this->edit(['qx_f' => '1']);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        $this->edit(['qx_g' => '1'], ['qx_f']);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        $this->edit(['qx_h' => FieldWizard::MARK_INFO]);
        self::assertTrue($wizard->compare(), FieldWizard::state()['message']);
        self::assertSame('room', FieldWizard::state()['step']);
        self::assertTrue($wizard->skip(), 'the stand-in has no room field');
        self::assertSame('verify', FieldWizard::state()['step']);

        $expected = self::standinFieldMap();
        $expected['info_text']['format'] = 'text';
        self::assertSame($expected, Options::get()['field_map']);
        self::assertFalse(Options::get()['field_map_verified'], 'not verified before step 3');

        // Step 3: the wizard writes a draft; the stand-in's own edit screen shows the expected values.
        $test = $wizard->writeTestDraft();
        self::assertSame('draft', get_post_status($test));
        self::assertSame(FieldWizard::TEST_TITLE, get_the_title($test));
        self::assertSame([
            'qx_a' => '2031-05-14', 'qx_b' => '18:30', 'qx_c' => '20:00', 'qx_e' => 'Mi', 'qx_h' => 'Prüfraum, Musterstrasse 1, 4000 Basel', 'qx_d' => '1', 'qx_g' => '1',
        ], qtp_standin_form_values($test));
        foreach (FieldWizard::selfCheck($test) as $field => $check) {
            self::assertTrue($check['same'], $field);
        }
        self::assertTrue($wizard->confirm());
        self::assertTrue(Options::get()['field_map_verified']);
        self::assertSame('trash', get_post_status($test));
        self::assertSame('trash', get_post_status($this->draft));

        // The learned mapping syncs the calendar onto the stand-in's programme page.
        $report = $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:00');
        self::assertFalse($report->dryRun);
        self::assertSame('Freitag', self::programmeRows('Test: Theater Niemandsland')[0]['day']);
        self::assertSame('18:00 - 21:00 Uhr', self::programmeRows('Test: Theater Niemandsland')[0]['time']);
        self::assertSame('Di., 05.01.2027', self::programmeRows('Test: Konzert Terrible two')[0]['day']);
    }

    public function testRequiredFieldsCannotBeSkippedAndRelearnResets(): void
    {
        $this->draft = $this->createLearningDraft();
        $wizard = new FieldWizard();
        self::assertTrue($wizard->begin());
        $this->edit([]);
        self::assertTrue($wizard->compare());
        self::assertFalse($wizard->skip(), 'the date is required');
        $this->edit(['qx_a' => '2031-04-24']);
        self::assertFalse($wizard->compare(), 'a wrong sample value is not learned');
        self::assertStringContainsString('_qtp_when', FieldWizard::state()['message'], 'the message lists what changed');
        FieldWizard::reset();
        self::assertSame([], Options::get()['field_map']);
        self::assertFalse(Options::get()['field_map_verified']);
        self::assertTrue($this->sync(self::fixture('calendar.ics'), '2027-01-04 00:00')->dryRun, 'R-11 after «Neu lernen»');
    }

    public function testPluginCodeNeverNamesTheStandInFields(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(glob($root . '/src/WordPress/*.php') ?: [], glob($root . '/src/Core/*.php') ?: [], [$root . '/enter-once-sync.php']);
        self::assertGreaterThan(20, count($files));
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach (['_qtp_', 'qx_', 'qtp_standin', '_qtp_when', 'rhythm', 'storno'] as $needle) {
                self::assertStringNotContainsString($needle, $code, basename($file) . " mentions $needle");
            }
        }
    }
}
