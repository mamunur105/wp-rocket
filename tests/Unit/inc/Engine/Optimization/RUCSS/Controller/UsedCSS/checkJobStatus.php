<?php

use WP_Rocket\Admin\Options_Data;
use WP_Rocket\Engine\Common\Context\ContextInterface;
use WP_Rocket\Engine\Common\Queue\QueueInterface;
use WP_Rocket\Engine\Optimization\RUCSS\Controller\Filesystem;
use WP_Rocket\Engine\Optimization\RUCSS\Controller\UsedCSS;
use WP_Rocket\Engine\Optimization\RUCSS\Database\Queries\UsedCSS as UsedCSS_Query;
use WP_Rocket\Engine\Optimization\RUCSS\Database\Row\UsedCSS as UsedCSS_Row;
use WP_Rocket\Engine\Optimization\RUCSS\Frontend\APIClient;
use WP_Rocket\Logger\Logger;
use WP_Rocket\Tests\Unit\TestCase;
use WP_Rocket\Engine\Optimization\DynamicLists\DefaultLists\DataManager;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;
use WP_Rocket\Engine\Optimization\RUCSS\Strategy\Factory\StrategyFactory;
use WP_Rocket\Engine\Common\Clock\WPRClock;


/**
 * @covers \WP_Rocket\Engine\Optimization\RUCSS\Controller\UsedCSS::check_job_status
 *
 * @group  RUCSS
 */
class Test_CheckJobStatus extends TestCase {
	use \WP_Rocket\Tests\Unit\HasLoggerTrait;

	protected $options;
	protected $usedCssQuery;
	protected $api;
	protected $queue;
	protected $usedCss;
	protected $data_manager;
	protected $filesystem;

	protected $strategy_factory;

	/**
	 * @var WPRClock
	 */
	protected $wpr_clock;

	protected function setUp(): void {
		parent::setUp();
		$this->options      = Mockery::mock( Options_Data::class );
		$this->usedCssQuery = $this->createMock( UsedCSS_Query::class );
		$this->api          = Mockery::mock( APIClient::class );
		$this->queue        = Mockery::mock( QueueInterface::class );
		$this->data_manager = Mockery::mock( DataManager::class );
		$this->filesystem   = Mockery::mock( Filesystem::class );
		$this->context = Mockery::mock(ContextInterface::class);
		$this->optimisedContext = Mockery::mock(ContextInterface::class);
		$this->wpr_clock = Mockery::mock(WPRClock::class);
		$this->strategy_factory = Mockery::mock(StrategyFactory::class, [$this->usedCssQuery, $this->wpr_clock]);

		$this->usedCss      = Mockery::mock(
			UsedCSS::class . '[is_allowed,update_last_accessed,add_url_to_the_queue]',
			[
				$this->options,
				$this->usedCssQuery,
				$this->api,
				$this->queue,
				$this->data_manager,
				$this->filesystem,
				$this->context,
				$this->optimisedContext,
				$this->strategy_factory,
				$this->wpr_clock,
			]
		);


		$this->set_logger($this->usedCss);
	}

	protected function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * @dataProvider configTestData
	 */
	public function testShouldReturnAsExpected( $config, $expected ) {
		$this->logger->allows()->error(Mockery::any());
		if ( $config['row_details'] ) {
			$row_details = new UsedCSS_Row( $config['row_details'] );
		} else {
			$row_details = null;
		}
		if ( isset( $config['job_details'] ) ) {
			$job_details = $config['job_details'];
		}
		if ( isset( $job_details['contents']['shakedCSS'] ) ) {
			$css  = $job_details['contents']['shakedCSS'];
			$hash = md5( $css );
		}
		if ( isset( $config['is_used_css_file_written'] ) ) {
			$is_file_written = $config['is_used_css_file_written'];
		}
		$this->usedCssQuery->expects( self::once() )
		                   ->method( 'get_item' )
		                   ->with( $config['job_id'] )
		                   ->willReturn( $row_details );
		if ( ! $row_details ) {
			return;
		}
		Functions\expect( 'home_url' )
			->with()
			->zeroOrMoreTimes()
			->andReturn( $row_details->url );

		$this->api->expects()
		          ->get_queue_job_status( $row_details->job_id, $row_details->queue_name, $row_details->is_home )
		          ->andReturn( $job_details );
		$min_rucss_size = 150;
		Filters\expectApplied( 'rocket_min_rucss_size' )->andReturn( $min_rucss_size );
		if( isset( $job_details['contents']['shakedCSS_size'] ) && intval( $job_details['contents']['shakedCSS_size'] ) < $min_rucss_size){
			$message = 'RUCSS: shakedCSS size is less than ' . $min_rucss_size;
			$this->usedCssQuery->expects( self::once() )
			                   ->method( 'make_status_failed' )
			                   ->with( $config['job_id'], '500', $message );
			$this->usedCss->check_job_status( $config['job_id'] );
			return;
		}
		if (
			200 !== $job_details['code']
		) {
			$this->strategy_factory->expects( 'manage' )->with( $row_details, $job_details );

			$this->usedCss->check_job_status( $config['job_id'] );
			return;
		}


		$this->filesystem->shouldReceive( 'write_used_css' )
		                 ->atMost()
		                 ->once()
		                 ->with( $hash, $css )
		                 ->andReturn( $is_file_written );

		if ( ! $is_file_written ) {
			$message = 'RUCSS: Could not write used CSS to the filesystem: ' . $row_details->url;
			$this->usedCssQuery->expects( self::once() )
			                   ->method( 'make_status_failed' )
			                   ->with( $config['job_id'], '', $message );

			$this->usedCss->check_job_status( $config['job_id'] );

			return;
		} else {
			$this->usedCssQuery->expects( self::once() )
			                   ->method( 'make_status_completed' )
			                   ->with( $config['job_id'], $hash );

			Actions\expectDone('rocket_rucss_complete_job_status')->with( $row_details->url, $job_details );
		}
		$this->usedCss->check_job_status( $config['job_id'] );
	}

}
