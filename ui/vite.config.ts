import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

/**
 * Builds the host mount into `public/ui/` with stable file names, so the PHP
 * template can reference `/ui/app.js` and `/ui/app.css` without a manifest.
 */
export default defineConfig({
	plugins: [vue()],
	build: {
		outDir: '../public/ui',
		emptyOutDir: true,
		cssCodeSplit: false,
		rollupOptions: {
			input: 'src/main.ts',
			output: {
				entryFileNames: 'app.js',
				chunkFileNames: 'app-[name].js',
				assetFileNames: 'app.[ext]',
			},
		},
	},
});
