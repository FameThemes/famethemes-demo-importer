
#https://github.com/10up/action-wordpress-plugin-deploy/blob/develop/deploy.sh
cmd="mkdir -p ./SVN"
$cmd
# cd SVN

SLUG="famethemes-demo-importer"
SVN_DIR="${HOME}/SVN"



SVN_URL="https://plugins.svn.wordpress.org/${SLUG}/"

echo "➤ Checking out .org repository..."
svn checkout --depth immediates "$SVN_URL" "$SVN_DIR"


# cd "$SVN_DIR"


echo "ℹ︎ Copying files from build directory..."


rsync -rc --exclude-from="${HOME}/.gitignore" "${SVN_DIR}trunk/" --delete --delete-excluded



# Add everything and commit to SVN
# The force flag ensures we recurse into subdirectories even if they are already added
# Suppress stdout in favor of svn status later for readability
# echo "➤ Preparing files..."
# svn add . --force > /dev/null