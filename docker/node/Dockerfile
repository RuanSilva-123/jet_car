# Ambiente de desenvolvimento do Angular (ng serve com hot reload).
FROM node:24-alpine

WORKDIR /app

COPY docker/node/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 4200

ENTRYPOINT ["entrypoint.sh"]
CMD ["npx", "ng", "serve", "--host", "0.0.0.0", "--port", "4200", "--allowed-hosts=localhost", "--poll", "1000"]
