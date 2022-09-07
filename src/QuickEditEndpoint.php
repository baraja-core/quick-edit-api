<?php

declare(strict_types=1);

namespace Baraja\QuickEdit;


use Baraja\QuickEdit\Attribute\Editable;
use Baraja\ServiceMethodInvoker;
use Baraja\StructuredApi\BaseEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\Mapping\ClassMetadata;

final class QuickEditEndpoint extends BaseEndpoint
{
	private const TypeMapper = [
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
		string $type = 'text',
	): void {
		/** @var \Doctrine\ORM\Mapping\ClassMetadata $metadata */
		$metadata = $this->getEntityClass($entity);
		$class = $metadata->getName();
		try {
			/** @var object|null $selectedEntity */
			$selectedEntity = (new EntityRepository($this->entityManager, $metadata))
				->createQueryBuilder('e')
				->where('e.id = :id')
				->setParameter('id', $id)
				->setMaxResults(1)
				->getQuery()
				->getOneOrNullResult();
		} catch (NonUniqueResultException) {
			throw new \InvalidArgumentException(sprintf('Entity "%s" with identifier "%s" is not unique.', $class, $id));
		}
		if ($selectedEntity === null) {
			throw new \InvalidArgumentException(sprintf('Entity "%s" with identifier "%s" does not exist.', $class, $id));
		}
		$setter = 'set' . $property;
		if (\method_exists($selectedEntity, $setter) === false) {
			throw new \InvalidArgumentException(sprintf(
				'Entity "%s" with identifier "%s" can not be changed, because setter "%s" does not exist.',
				$class,
				$id,
				$setter,
			));
		}
		$value = $this->valueNormalize($type, $value);
		try {
			$ref = new \ReflectionMethod($selectedEntity, $setter);
			$isEditable = $ref->getAttributes(Editable::class) !== [];
			$isEditableByAnnotation = str_contains((string) $ref->getDocComment(), '@editable'); // legacy annotation support
			if (!$isEditable && !$isEditableByAnnotation) {
				throw new \LogicException(sprintf('Method "%s" do not implement attribute #[Editable].', $setter));
			}
			if ($isEditableByAnnotation) {
				trigger_error(
					sprintf(
						'Annotation "@editable" (in class "%s" and method "%s") is deprecated, please use attribute #[Editable] instead.',
						$ref->getDeclaringClass()->getName(),
						$ref->getName(),
					),
					E_USER_DEPRECATED,
				);
			}
			$ref->setAccessible(true);
			$param = $ref->getParameters()[0] ?? null;
			if ($param === null) {
				throw new \InvalidArgumentException(sprintf('First input argument for method "%s" is required.', $setter));
			}
			if (isset($ref->getParameters()[1])) {
				throw new \InvalidArgumentException(sprintf('Method "%s" implements too many arguments. Did you use one argument only?', $setter));
			}
			(new ServiceMethodInvoker)->invoke($selectedEntity, $setter, [
				$param->getName() => $value,
			]);
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException(
				sprintf('Value for entity "%s" with identifier "%s" can not be changed: %s', $class, $id, $e->getMessage()),
				$e->getCode(),
				$e,
			);
		}

		$this->entityManager->flush();
		$this->flashMessage(sprintf('Property "%s" has been changed.', $property), 'success');
		$this->sendOk();
	}


	private function getEntityClass(string $name): ClassMetadata
	{
		if (class_exists($name)) {
			$entity = $name;
		} else {
			$entity = null;
			$name = str_replace('-', '', strtolower($name));
			foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $meta) {
				if (strtolower((string) preg_replace('/^.*?([^\\\]+)$/', '$1', $meta->getName())) === $name) {
					if ($entity !== null) {
						throw new \InvalidArgumentException(sprintf('The name "%s" is not unambiguous. Entity "%s" and "%s" correspond to this name.', $name, $entity, $meta->getName()));
					}
					$entity = $meta->getName();
				}
			}
		}
		try {
			$entityClassString = (string) $entity;
			if (class_exists($entityClassString) === false) {
				throw new \LogicException(sprintf('String "%s" is not valid entity name.', $entityClassString));
			}

			return $this->entityManager->getMetadataFactory()->getMetadataFor($entityClassString);
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException(sprintf('Class "%s" is not valid Doctrine entity.', $entityClassString), 500, $e);
		}
	}


	private function valueNormalize(string $type, mixed $value): float|bool|int|string
	{
		$type = self::TypeMapper[$type] ?? 'string';
		if ($type === 'bool') {
			return $value === 'true' || $value === '1';
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
