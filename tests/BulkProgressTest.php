<?php
/**
 * Bulk progress maths and state shape.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class BulkProgressTest
 */
class BulkProgressTest extends TestCase {

	/**
	 * The state carries the fields the live progress display depends on.
	 */
	public function test_default_state_exposes_progress_fields() {
		$state = GEO_Bulk::default_state();

		$this->assertArrayHasKey( 'current_id', $state );
		$this->assertArrayHasKey( 'current_title', $state );
		$this->assertArrayHasKey( 'lock', $state );

		$this->assertSame( 0, $state['current_id'] );
		$this->assertSame( '', $state['current_title'] );
		$this->assertSame( 0, $state['lock'] );
	}

	/**
	 * An untouched run reports zero percent instead of dividing by zero.
	 */
	public function test_progress_percent_without_total_is_zero() {
		$this->assertSame( 0, GEO_Bulk::progress_percent( array( 'total' => 0, 'processed' => 0 ) ) );
		$this->assertSame( 0, GEO_Bulk::progress_percent( array() ) );
	}

	/**
	 * Percent rounds to whole numbers.
	 */
	public function test_progress_percent_rounds() {
		$this->assertSame( 0, GEO_Bulk::progress_percent( array( 'total' => 114, 'processed' => 0 ) ) );
		$this->assertSame( 50, GEO_Bulk::progress_percent( array( 'total' => 114, 'processed' => 57 ) ) );
		$this->assertSame( 33, GEO_Bulk::progress_percent( array( 'total' => 3, 'processed' => 1 ) ) );
		$this->assertSame( 100, GEO_Bulk::progress_percent( array( 'total' => 20, 'processed' => 20 ) ) );
	}

	/**
	 * Overshoot is clamped, never above 100 or below 0.
	 */
	public function test_progress_percent_is_clamped() {
		$this->assertSame( 100, GEO_Bulk::progress_percent( array( 'total' => 10, 'processed' => 14 ) ) );
		$this->assertSame( 0, GEO_Bulk::progress_percent( array( 'total' => 10, 'processed' => -3 ) ) );
	}

	/**
	 * Remaining posts never go negative.
	 */
	public function test_remaining_is_never_negative() {
		$this->assertSame( 114, GEO_Bulk::remaining( array( 'total' => 114, 'processed' => 0 ) ) );
		$this->assertSame( 14, GEO_Bulk::remaining( array( 'total' => 114, 'processed' => 100 ) ) );
		$this->assertSame( 0, GEO_Bulk::remaining( array( 'total' => 114, 'processed' => 114 ) ) );
		$this->assertSame( 0, GEO_Bulk::remaining( array( 'total' => 114, 'processed' => 200 ) ) );
	}

	/**
	 * The run remembers whether it may overwrite existing FAQ.
	 */
	public function test_default_state_tracks_force_flag() {
		$state = GEO_Bulk::default_state();

		$this->assertArrayHasKey( 'force', $state );
		$this->assertFalse( $state['force'] );
	}

	/**
	 * Budget maths: one post can never outlive the request budget, and the
	 * reserve before opening a new post stays inside it.
	 */
	public function test_time_budget_bounds_a_single_post() {
		$this->assertGreaterThan( 0, GEO_Bulk::TIME_BUDGET );
		$this->assertLessThanOrEqual( GEO_Bulk::TIME_BUDGET, GEO_Bulk::MAX_POST_TIMEOUT );
		$this->assertLessThan( GEO_Bulk::TIME_BUDGET, GEO_Bulk::MIN_POST_SECONDS );
	}

	/**
	 * A batch hand-off keeps the cursor and the counters consistent.
	 */
	public function test_partial_batch_state_stays_consistent() {
		$state = array(
			'status'    => 'running',
			'total'     => 100,
			'processed' => 37,
			'cursor'    => 4210,
		);

		$this->assertSame( 37, GEO_Bulk::progress_percent( $state ) );
		$this->assertSame( 63, GEO_Bulk::remaining( $state ) );

		// Simulate the time budget cutting a batch short.
		$state['processed'] = 42;
		$state['cursor']    = 4290;

		$this->assertSame( 42, GEO_Bulk::progress_percent( $state ) );
		$this->assertSame( 58, GEO_Bulk::remaining( $state ) );
	}

	/**
	 * A retry pass reports its own queue, not the batch cursor.
	 *
	 * Without this the bar sits frozen at 100% while five failed articles are
	 * being regenerated, which is exactly the confusing UI this replaced.
	 */
	public function test_retry_progress_ignores_batch_counters() {
		$state = array(
			'mode'        => 'retry',
			'total'       => 114,
			'processed'   => 114,
			'retry_total' => 5,
			'retry_done'  => 2,
		);

		$this->assertTrue( GEO_Bulk::is_retrying( $state ) );
		$this->assertSame( 40, GEO_Bulk::progress_percent( $state ) );
		$this->assertSame( 3, GEO_Bulk::remaining( $state ) );
	}

	/**
	 * The batch counters come back as soon as the retry queue is done.
	 */
	public function test_batch_progress_returns_after_retry() {
		$state = array(
			'mode'        => 'batch',
			'total'       => 114,
			'processed'   => 114,
			'retry_total' => 5,
			'retry_done'  => 5,
		);

		$this->assertFalse( GEO_Bulk::is_retrying( $state ) );
		$this->assertSame( 100, GEO_Bulk::progress_percent( $state ) );
		$this->assertSame( 0, GEO_Bulk::remaining( $state ) );
	}

	/**
	 * Defaults are a batch run with an empty retry queue.
	 */
	public function test_default_state_starts_in_batch_mode() {
		$state = GEO_Bulk::default_state();

		$this->assertSame( 'batch', $state['mode'] );
		$this->assertSame( array(), $state['retry_queue'] );
		$this->assertSame( 0, $state['retry_total'] );
		$this->assertSame( 0, $state['retry_done'] );
	}

	/**
	 * A retry gets more runway per article than a batch pass, so items that
	 * merely needed 35 seconds are not retried forever at the same cap.
	 */
	public function test_retry_budget_is_wider_than_batch_budget() {
		$this->assertGreaterThan( GEO_Bulk::TIME_BUDGET, GEO_Bulk::RETRY_BUDGET );
		$this->assertGreaterThan( GEO_Bulk::MAX_POST_TIMEOUT, GEO_Bulk::RETRY_MAX_POST );
		$this->assertLessThanOrEqual( GEO_Bulk::RETRY_BUDGET, GEO_Bulk::RETRY_MAX_POST );
	}
}
