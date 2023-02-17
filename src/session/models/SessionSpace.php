<?php

namespace mhthnz\tarantool\session\models;

use yii\validators\NumberValidator;
use yii\validators\RequiredValidator;
use yii\validators\StringValidator;

/**
 * Session space.
 *
 * @property string $id     Session ID
 * @property int    $expire Session Expire unixtimestamp
 * @property string $data   Session  Data
 */
class SessionSpace extends \mhthnz\tarantool\ActiveRecord {
	/**
	 * {@inheritdoc}
	 */
	public function rules(): array {
		return [
			['id',     RequiredValidator::class],
			['id',     StringValidator  ::class],
			['expire', RequiredValidator::class],
			['expire', NumberValidator  ::class],
			['data',   RequiredValidator::class],
			['data',   StringValidator  ::class],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public static function tableName(): string {
		return '{{%session}}';
	}
}
