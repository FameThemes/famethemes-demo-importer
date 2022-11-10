source svn-config.cfg

# Remove folder if exists.
rm -rf SVN
cmd="mkdir -p ./SVN"
$cmd
# cd SVN

SLUG="${SVN_REPO}"
SVN_DIR="./SVN"


SVN_URL="https://plugins.svn.wordpress.org/${SLUG}/"

echo "Checking out repository: ${SVN_URL}"
svn checkout --depth immediates "${SVN_URL}" "${SVN_DIR}"

# cd "$SVN_DIR"
echo "Copying files to SVN/trunk..."

cd "${SVN_DIR}"

svn update --set-depth infinity assets
svn update --set-depth infinity trunk

cd ../

rsync -rc --exclude-from=".distignore" . "${SVN_DIR}/trunk/" --delete --delete-excluded


cd "${SVN_DIR}"


# Add everything and commit to SVN
# The force flag ensures we recurse into subdirectories even if they are already added
# Suppress stdout in favor of svn status later for readability
echo "Preparing files..."
svn add . --force > /dev/null


# SVN delete all deleted files
# Also suppress stdout here
svn status | grep '^\!' | sed 's/! *//' | xargs -I% svn rm %@ > /dev/null

#Resolves => SVN commit failed: Directory out of date
svn update

svn status


echo "Committing files..."
svn commit -m "Update new version" --no-auth-cache --non-interactive  --username "${SVN_USERNAME}" --password "${SVN_PASSWORD}"

echo "Removed folder SVN"
# Remove folder if exists.
rm -rf SVN


echo "Plugin deployed!"


