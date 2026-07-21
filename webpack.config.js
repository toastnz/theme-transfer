const path = require('path');
const TerserPlugin = require("terser-webpack-plugin");
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');
const FriendlyErrorsWebpackPlugin = require('@soda/friendly-errors-webpack-plugin');

const stats = {
  colors: true,
  hash: false,
  version: false,
  timings: false,
  assets: false,
  chunks: false,
  modules: false,
  reasons: false,
  children: false,
  source: false,
  errors: false,
  errorDetails: false,
  warnings: false,
  publicPath: false,
};

// The main directory (outside the webpack folder)
const dir = path.resolve(__dirname, './');
// Our aliases
const aliases = {
  'styles': path.resolve(__dirname, './client/source/styles/'),
  'scripts': path.resolve(__dirname, './client/source/scripts/'),
};

// Our marmalade config (imports the cms theme, dev theme and blocks to the frontend)
const app = {
  dir,
  files: ['main'],
  entries: {},
  output: {
    publicPath: '/client/dist/scripts/',
    path: path.resolve(__dirname, './client/dist/scripts'),
    filename: '[name].js',
    chunkFilename: 'components/[chunkhash].js',
    clean: true,
  },
  plugins: [],
  resolve: { alias: aliases },
};

/* Loop through the marmalade entry points and add them to the entries object */
for (let index = 0; index < app.files.length; index++) {
  const file = app.files[index];
  app.entries[file] = path.resolve(__dirname, `./client/source/scripts/${file}.js`);
}

const configs = [];

[app].forEach((config) => {
  configs.push((env, argv) => {
    const isProduction = argv.mode === 'production';
    const isDevelopment = !isProduction;

    return {
      mode: isProduction ? 'production' : 'development',
      stats,
      devtool: 'source-map',
      entry: config.entries,
      output: config.output,
      resolve: config.resolve,
      module: {
        rules: [
          {
            test: /\.js$/,
            exclude: /node_modules/,
            use: [
              { loader: 'babel-loader', options: { sourceMap: isDevelopment } },
            ],
          },
          {
            test: /\.scss$/,
            use: [
              MiniCssExtractPlugin.loader,
              {
                loader: 'css-loader',
                options: {
                  sourceMap: isDevelopment,
                  url: false,
                }
              },
              {
                loader: 'sass-loader', options: {
                  sourceMap: isDevelopment,
                }
              },
            ]
          },
        ]
      },
      optimization: {
        minimizer: [
          new TerserPlugin({
            minify: TerserPlugin.uglifyJsMinify,
          }),
          new CssMinimizerPlugin()
        ]
      },
      plugins: [
        new FriendlyErrorsWebpackPlugin(),
        new MiniCssExtractPlugin({
          filename: '../styles/[name].css',
          chunkFilename: '[name].css'
        }),
      ].filter(Boolean),
    }
  });
});

module.exports = configs;
