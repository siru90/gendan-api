FROM rabbitmq:3.11-management

COPY ./rabbitmq_delayed_message_exchange-3.11.1/ /opt/rabbitmq/plugins/rabbitmq_delayed_message_exchange-3.11.1
RUN rabbitmq-plugins enable --offline rabbitmq_delayed_message_exchange
