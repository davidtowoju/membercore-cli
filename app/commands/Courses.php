<?php
namespace membercore\cli\commands;

use membercore\courses\models\Course;
use membercore\courses\models\Section;
use membercore\courses\models\Lesson;

class Courses {


	protected $faker;

	public function __construct() {
		$this->faker = \Faker\Factory::create();
	}

	/**
	 * Create Courses
	 *
	 * @alias make-courses
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : How many courses.
	 * ---
	 * default: 5
	 * ---
	 */
	function create_courses( $args, $assoc_args ) {
		list( $count ) = $args;

		for ( $i = 1; $i <= $count; $i++ ) {

			$post = array(
				'post_title'   => $this->faker->sentence( 5 ),
				'post_content' => $this->faker->paragraph( 4 ),
				'post_type'    => Course::$cpt,
				'post_status'  => 'publish',
			);

			// Insert the post into the database.
			wp_insert_post( $post );

		}

		// todo: create course + sections + lessons

		\WP_CLI::success( 'Courses created' );
	}

	/**
	 * Create sections.
	 *
	 * @alias make-sections
	 *
	 * <course_id>
	 * : The ID of the course.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : How many courses.
	 * ---
	 * default: 5
	 *
	 * [--lessons=<lessons>]
	 * : How many lessons per course.
	 * ---
	 * default: false
	 * ---
	 */
	public function create_sections( $args, $assoc_args ) {
		list( $course_id ) = $args;
		$count             = $assoc_args['count'];
		$with_lessons      = $assoc_args['lessons'];

		if ( ! $course_id ) {
			\WP_CLI::error( 'Course ID must be provided' );
		}

		if ( ! is_numeric( $count ) || $count < 0 ) {
			\WP_CLI::error( 'Count must be greater than 0' );
		}

		// create sections + lessons
		$course = new Course( $course_id );

		if ( ! $course->ID ) {
			\WP_CLI::error( 'Course not found, please check the ID' );
		}

		// create sections
		for ( $i = 1; $i <= $count; $i++ ) {
			$section_title = $i . substr( date( 'jS', mktime( 0, 0, 0, 1, ( $i % 10 == 0 ? 9 : ( $i % 100 > 20 ? $i % 10 : $i % 100 ) ), 2000 ) ), -2 ) . ' Section';
			$sections[]    = new Section(
				array(
					'title'         => $section_title,
					'uuid'          => wp_generate_uuid4(),
					'course_id'     => $course->ID,
					'section_order' => $i - 1,
				)
			);
		}

		// maybe add lessons to sections if stated
		foreach ( $sections as $section ) {
			$section_id = $section->store();
			$section    = new Section( $section_id );

			if ( ! is_numeric( $with_lessons ) || $with_lessons < 0 ) {
				continue;
			}
			// dump($with_lessons);
			for ( $i = 1; $i <= $with_lessons; $i++ ) {

				$post = array(
					'post_title'   => $this->faker->sentence( 5 ),
					'post_content' => $this->faker->paragraph( 4 ),
					'post_type'    => Lesson::$cpt,
					'post_status'  => 'publish',
				);

				// Insert the post into the database.
				$lesson_id = wp_insert_post( $post );

				$lesson               = new Lesson( $lesson_id );
				$lesson->section_id   = $section_id;
				$lesson->lesson_order = $i - 1;

				$lesson->store();
			}
		}

		\WP_CLI::success( 'Sections created' );
	}

	/**
	 * Generate lessons.
	 *
	 * @alias make-lessons
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : How many courses.
	 * ---
	 * default: 5
	 * ---
	 */
	function create_lessons( $args, $assoc_args ) {
		list( $action ) = $args;

		// todo: create lessons

		\WP_CLI::success( 'Courses created' );
	}
}
