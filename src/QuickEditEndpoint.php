<?php

declare(strict_types=1);

namespace Baraja\QuickEdit;


use Baraja\StructuredApi\BaseEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\Mapping\ClassMetadata;

final class QuickEditEndpoint extends BaseEndpoint
{
	private const TYPE_MAPPER = [
		'text' => 'string',
		'int' => 'int',
		'integer' => 'int',
		'float' => 'float',
		'bool' => 'bool',
		'boolean' => 'bool',
	];


	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
	}


	public function actionDefault(
		string $entity,
		string $property,
		string $id,
		mixed $value,
		string $type = 'text'
	): void {
		/** @var \Doctrine\ORM\Mapping\ClassMetadata $metadata */
		$metadata = $this->getEntityClass($entity);
		$class = $metadata->getName();
		try {
			$selectedEntity = (new EntityRepository($this->entityManager, $metadata))
				->createQueryBuilder('e')
				->where('e.id = :id')
				->setParameter('id', $id)
				->setMaxResults(1)
				->getQuery()
				->getOneOrNullResult();
		} catch (NonUniqueResultException) {
			throw new \InvalidArgumentException('Entity "' . $class . '" with identifier "' . $id . '" is not unique.');
		}
		if ($selectedEntity === null) {
			throw new \InvalidArgumentException('Entity "' . $class . '" with identifier "' . $id . '" does not exist.');
		}
		if (\method_exists($selectedEntity, $setter = 'set' . $property) === false) {
			throw new \InvalidArgumentException(
				'Entity "' . $class . '" with identifier "' . $id . '" can not be changed, '
				. 'because setter "' . $setter . '" does not exist.',
			);
		}
		try {
			$ref = new \ReflectionMethod($selectedEntity, $setter);
			if (stripos((string) $ref->getDocComment(), '@editable') === false) {
				throw new \LogicException('Method "' . $setter . '" do not implement "@editable" annotation.');
			}
			$ref->setAccessible(true);
			$ref->invoke($selectedEntity, $this->valueNormalize($type, $value));
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException(
				'Value for entity "' . $class . '" with identifier "' . $id . '" '
				. 'can not be changed: ' . $e->getMessage(),
				$e->getCode(),
				$e,
			);
		}

		$this->entityManager->flush();
		$this->sendOk();
	}


	private function getEntityClass(string $name): ClassMetadata
	{
		if (\class_exists($name)) {
			$entity = $name;
		} else {
			$entity = null;
			$name = str_replace('-', '', strtolower($name));
			foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $meta) {
				if (strtolower((string) preg_replace('/^.*?([^\\\]+)$/', '$1', $meta->getName())) === $name) {
					if ($entity !== null) {
						throw new \InvalidArgumentException(
							'The name "' . $name . '" is not unambiguous. '
							. 'Entity "' . $entity . '" and "' . $meta->getName() . '" correspond to this name.',
						);
					}
					$entity = $meta->getName();
				}
			}
		}
		try {
			return $this->entityManager->getMetadataFactory()->getMetadataFor((string) $entity);
		} catch (\Throwable) {
			throw new \InvalidArgumentException('Class "' . $entity . '" is not valid Doctrine entity.');
		}
	}


	private function valueNormalize(string $type, mixed $value): float|bool|int|string
	{
		$type = self::TYPE_MAPPER[$type] ?? 'string';
		if ($type === 'bool') {
			return $value === 'true';
		}
		if ($type === 'float') {
			return (float) $value;
		}
		if ($type === 'int') {
			return (int) $value;
		}

		return (string) $value;
	}
}
