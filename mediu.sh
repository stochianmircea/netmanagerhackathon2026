# mediu.sh — pune in mediu variabilele din .env.
# NU executa fisierul: il citeste rind cu rind. Numai numele din lista alba ajung in mediu.
while IFS='=' read -r k v; do
  case "$k" in
    META_API_KEY|META_API_BASE|META_MODEL_NAME|LOCAL_MODEL_URL|LOCAL_MODEL_NAME|MODEL_TIMEOUT|COMPILER_PROVIDER) ;;
    *) continue ;;
  esac
  v="${v%$'\r'}"
  v="${v#\"}"; v="${v%\"}"; v="${v#\'}"; v="${v%\'}"
  export "$k=$v"
done < "$(dirname "${BASH_SOURCE[0]}")/.env"
