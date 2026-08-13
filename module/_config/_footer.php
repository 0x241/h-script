				</main>
				<?php if ($cfgLogged) { ?>
					<footer class="flex flex-col items-center justify-between gap-3 border-t border-gray-200 px-5 py-5 text-xs font-semibold text-gray-400 dark:border-gray-800 sm:flex-row lg:px-10">
						<span><strong class="text-gray-500 dark:text-gray-300">H-Script Configurator</strong> &copy; <?php echo date('Y'); ?></span>
						<a href="#content" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 transition hover:bg-gray-100 hover:text-brand dark:hover:bg-[#1A1A1A] dark:hover:text-white"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i><?php echo cfg_t('Наверх', 'Back to top'); ?></a>
					</footer>
				</div>
			</div>
			<?php } else { ?>
			</div>
			<?php } ?>
	</body>
</html>
