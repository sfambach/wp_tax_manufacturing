<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * Why a manufacturing step cannot be made; the boundary turns it into a WP_Error (CD-10).
 *
 * The message is a code plus a detail, not user text: the boundary words it through the text domain (AR-2).
 */
final class ManufacturingError extends \RuntimeException
{
    private function __construct(public readonly string $reason, public readonly string $detail)
    {
        parent::__construct($reason . ': ' . $detail);
    }

    public static function notConfigured(string $key): self
    {
        return new self('not_configured', $key);
    }

    public static function projectWithoutBoards(int $project): self
    {
        return new self('project_without_boards', (string) $project);
    }

    public static function countNotPositive(string $given): self
    {
        return new self('count_not_positive', $given);
    }

    public static function unknownOrder(int $order): self
    {
        return new self('unknown_order', (string) $order);
    }

    public static function assemblyNotOfOrder(int $assembly, int $order): self
    {
        return new self('assembly_not_of_order', $assembly . '/' . $order);
    }

    public static function unknownStatus(string $key): self
    {
        return new self('unknown_status', $key);
    }

    public static function positionNotOnRevision(int $position, int $revision): self
    {
        return new self('position_not_on_revision', $position . '/' . $revision);
    }

    public static function boardsOutOfRange(int $given, int $pieces): self
    {
        return new self('boards_out_of_range', $given . '/' . $pieces);
    }

    public static function testedBeyondFitted(int $tested, int $fitted): self
    {
        return new self('tested_beyond_fitted', $tested . '>' . $fitted);
    }

    public static function doneBeyondTested(int $done, int $tested): self
    {
        return new self('done_beyond_tested', $done . '>' . $tested);
    }

    public static function sparteNeeded(int $revision): self
    {
        return new self('sparte_needed', (string) $revision);
    }

    public static function stockRefused(string $message): self
    {
        return new self('stock_refused', $message);
    }

    public static function assembliesNotDone(int $order): self
    {
        return new self('assemblies_not_done', (string) $order);
    }

    public static function fromModel(string $message): self
    {
        return new self('model_refused', $message);
    }
}
