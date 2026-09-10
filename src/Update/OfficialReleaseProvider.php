<?php

namespace HScript\Update;

interface OfficialReleaseProvider
{
	/** @return array{version:string,tag:string,released_at:string,summary:string,changes:array,archive_name:string,archive_url:string,archive_sha256:string,sigstore_url:string} */
	public function latest(): array;

	/** @return array{version:string,tag:string,released_at:string,summary:string,changes:array,archive_name:string,archive_url:string,archive_sha256:string,sigstore_url:string} */
	public function byVersion(string $version): array;

	public function downloadArchive(array $release, string $target, int $maximumBytes): void;
}
